<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Authorization;

use Authorization\AuthorizationServiceInterface;
use Authorization\Exception\AuthorizationRequiredException;
use Authorization\IdentityDecorator;
use Authorization\Middleware\AuthorizationMiddleware;
use Cake\Core\Configure;
use Cake\Error\ExceptionTrap;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateHandler;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Realtime\LeaseCodec;
use Interact\Realtime\SubscriptionAuthorizerInterface;
use Interact\Realtime\SubscriptionHandler;
use Interact\Realtime\Subscriptions;
use Interact\Runtime\Factory;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Authorization\BridgeIsland;
use Interact\Test\Fixture\Authorization\PolicyIsland;
use Interact\Test\Fixture\Authorization\PublicParentIsland;
use Interact\Test\Fixture\Realtime\RealtimeFixtureIsland;
use Interact\Test\Fixture\Streaming\CollectingSseResponse;
use Interact\Test\TestSuite\Authorization\CallbackHandler;
use Interact\Test\TestSuite\Authorization\PolicyFixture;
use Interact\View\TemplateView;
use Laminas\Diactoros\UploadedFile;
use Psr\Http\Message\ResponseInterface;

/**
 * Runs the native Authorization middleware around real island rendering and HTTP update handling.
 */
class AuthorizationMiddlewareTest extends TestCase
{
    protected Factory $factory;

    protected SnapshotCodec $snapshots;

    protected ServerRequest $request;

    /**
     * Configure native templates and a browser session without disabling the middleware check.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.paths.templates', [dirname(__DIR__, 2) . '/Fixture/Authorization/templates/']);
        $registry = new Registry();
        foreach ([BridgeIsland::class, PolicyIsland::class, PublicParentIsland::class] as $class) {
            $registry->register($class);
        }

        $this->snapshots = new SnapshotCodec(str_repeat('m', 32));
        $this->factory = new Factory($registry, $this->snapshots);
        $this->request = new ServerRequest([
            'url' => '/_interact/update',
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ])->withAttribute('identity', ['allowed' => true]);
    }

    /**
     * Keep array identities independent of whether the Authentication package is installed.
     */
    protected function middleware(AuthorizationServiceInterface $service): AuthorizationMiddleware
    {
        return new AuthorizationMiddleware($service, ['identityDecorator' => IdentityDecorator::class]);
    }

    /**
     * Mount through native middleware and preserve the signed baseline for an independent update.
     */
    protected function snapshot(?Options $loading = null): string
    {
        $snapshot = '';
        $this->middleware(PolicyFixture::service())->process($this->request, new CallbackHandler(
            function (ServerRequest $request) use (&$snapshot, $loading): Response {
                $context = new IslandContext($request, 'default', '/_interact/update');
                $runtime = $this->factory->create(new TemplateView($request), $context);
                $snapshot = $runtime->mount('Policy', [], null, $context, $loading)->snapshot;

                return new Response();
            },
        ));

        return $snapshot;
    }

    public function testUsingTheTraitDoesNotImplicitlySkipTheMiddlewareCheck(): void
    {
        $this->expectException(AuthorizationRequiredException::class);
        $this->middleware(PolicyFixture::service())->process($this->request, new CallbackHandler(
            static function (ServerRequest $request): Response {
                new BridgeIsland()->setRequest($request);

                return new Response();
            },
        ));
    }

    public function testPublicIslandsCanExplicitlyAcknowledgeTheRequest(): void
    {
        $service = PolicyFixture::service();
        $response = $this->middleware($service)->process($this->request, new CallbackHandler(
            static function (ServerRequest $request): Response {
                $island = new PublicParentIsland()->setRequest($request);
                $island->authorize(new IslandContext($request, 'default', '/update'));

                return new Response();
            },
        ));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($service->authorizationChecked());
    }

    public function testRejectedUpdatesKeepTheirFailureResponsesWithRequiredAuthorization(): void
    {
        $snapshot = $this->snapshot();
        $session = $this->request->getSession()->id();
        $state = $this->snapshots->open($snapshot, $session, 'default');
        $otherSession = $this->snapshots->seal($state, 'another-session', 'default');
        unset($state['pagePath']);
        $olderSnapshot = $this->snapshots->seal($state, $session, 'default');
        $action = ['version' => 1, 'action' => 'increment'];
        $cases = [
            [409, 'invalid_snapshot', $action + ['snapshot' => $olderSnapshot]],
            [409, 'session_changed', $action + ['snapshot' => $otherSession]],
            [400, 'invalid_request', ['version' => 2]],
        ];
        $handler = new UpdateHandler($this->factory, new FailureResponder());

        foreach ($cases as [$status, $code, $body]) {
            PolicyIsland::resetTrace();
            $request = $this->request->withMethod('POST')->withParsedBody($body);
            $response = $this->middleware(PolicyFixture::service())->process($request, new CallbackHandler(
                static fn(ServerRequest $request): Response => $handler->handle($request, new Response()),
            ));

            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
            $this->assertStringContainsString('"code":"' . $code . '"', (string)$response->getBody());
            $this->assertSame([], PolicyIsland::trace());
        }
    }

    public function testDeferredRejectionsPreserveFeedbackAndValidMountsStillAuthorize(): void
    {
        $snapshot = $this->snapshot(new Options('lazy'));
        $request = $this->request
            ->withMethod('POST')
            ->withParsedBody([
                'version' => 1,
                'snapshot' => $snapshot,
                'initialize' => true,
            ]);
        $handler = new UpdateHandler($this->factory, new FailureResponder());
        $callback = new CallbackHandler(
            static fn(ServerRequest $request): Response => $handler->handle($request, new Response()),
        );
        $file = new UploadedFile('php://temp', 0, UPLOAD_ERR_OK, 'file.txt');

        PolicyIsland::resetTrace();
        $rejected = $this->middleware(PolicyFixture::service())->process(
            $request->withUploadedFiles(['file' => $file]),
            $callback,
        );
        $this->assertSame(400, $rejected->getStatusCode());
        $this->assertStringContainsString('"code":"invalid_request"', (string)$rejected->getBody());
        $this->assertSame([], PolicyIsland::trace());

        $loaded = $this->middleware(PolicyFixture::service())->process($request, $callback);
        $this->assertSame(200, $loaded->getStatusCode());
        $this->assertSame(['authorize', 'mount', 'view'], PolicyIsland::trace());
    }

    public function testActionErrorsDoNotSatisfyTheRequiredAuthorizationCheck(): void
    {
        Configure::write('App.paths.templates', [dirname(__DIR__, 2) . '/test_app/templates/']);
        $context = new IslandContext($this->request, 'default', '/_interact/update');
        $snapshot = $this->factory
            ->create(new TemplateView($this->request), $context)
            ->mount('Counter', [], null, $context)
            ->snapshot;
        $request = $this->request
            ->withMethod('POST')
            ->withParsedBody([
                'version' => 1,
                'snapshot' => $snapshot,
                'action' => 'adjust',
                'args' => [2],
            ]);
        $handler = new UpdateHandler($this->factory, new FailureResponder());

        $this->expectException(AuthorizationRequiredException::class);
        $this->middleware(PolicyFixture::service())->process($request, new CallbackHandler(
            static fn(ServerRequest $request): Response => $handler->handle($request, new Response()),
        ));
    }

    public function testNativePolicyDenialsBecomeSafe403UpdateResponses(): void
    {
        $snapshot = $this->snapshot();
        $request = $this->request
            ->withMethod('POST')
            ->withAttribute('identity', ['allowed' => false])
            ->withParsedBody(['version' => 1, 'snapshot' => $snapshot, 'action' => 'increment']);
        $response = $this->middleware(PolicyFixture::service())->process($request, new CallbackHandler(
            fn(ServerRequest $request): Response => new UpdateHandler($this->factory, new FailureResponder())->handle(
                $request,
                new Response(),
            ),
        ));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('"code":"forbidden"', (string)$response->getBody());
        $this->assertStringNotContainsString('private-policy-reason', (string)$response->getBody());
    }

    public function testRejectedOnlySubscriptionBatchReturnsMemberErrorsWithoutAGrant(): void
    {
        $now = 1000;
        $leases = new LeaseCodec(str_repeat('m', 32), clock: static function () use (&$now): int {
            return $now;
        });
        $expired = $this->lease($leases);
        $now = 1300;
        $provider = $this->createMock(SubscriptionAuthorizerInterface::class);
        $provider->expects($this->never())->method('authorize');

        RealtimeFixtureIsland::resetTrace();
        $response = $this->subscribe($leases, $provider, [
            'island' => $expired,
            'wrong-id' => $this->lease($leases),
            'malformed' => false,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $error = ['error' => 'Subscription authorization failed.'];
        $this->assertSame(
            [
                'version' => 1,
                'islands' => ['island' => $error, 'wrong-id' => $error, 'malformed' => $error],
                'grant' => null,
            ],
            json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame([], RealtimeFixtureIsland::trace());
    }

    public function testAValidLeaseStillRequiresAuthorizationEvenWhenItYieldsNoTopics(): void
    {
        $leases = new LeaseCodec(str_repeat('m', 32));
        $provider = $this->createMock(SubscriptionAuthorizerInterface::class);
        $provider->expects($this->never())->method('authorize');

        $this->expectException(AuthorizationRequiredException::class);
        $this->subscribe($leases, $provider, ['other' => false, 'island' => $this->lease($leases)]);
    }

    /**
     * Issue a real lease for an island that uses its own policy and currently has no topics.
     */
    protected function lease(LeaseCodec $leases): string
    {
        $context = new IslandContext($this->request, 'default', '/_interact/update');

        return $leases->seal(
            [
                'alias' => 'RealtimeFixture',
                'id' => 'island',
                'state' => ['recordId' => 1, 'subscribed' => false],
                'endpoint' => $context->endpoint,
            ],
            $context->binding(),
            $context->scope,
        );
    }

    /**
     * Renew real leases through native required-check middleware.
     *
     * @param array<string, mixed> $tokens Browser-supplied subscription leases.
     */
    protected function subscribe(
        LeaseCodec $leases,
        SubscriptionAuthorizerInterface $provider,
        array $tokens,
    ): ResponseInterface {
        $registry = new Registry();
        $registry->register(RealtimeFixtureIsland::class);

        $handler = new SubscriptionHandler($registry, $leases, $provider, new Subscriptions($leases));
        $request = $this->request
            ->withUri($this->request->getUri()->withPath('/_interact/subscribe'))
            ->withMethod('POST')
            ->withParsedBody(['version' => 1, 'leases' => $tokens]);

        return $this->middleware(PolicyFixture::service())->process($request, new CallbackHandler(
            static fn(ServerRequest $request): Response => $handler->handle($request, new Response()),
        ));
    }

    public function testStreamingChecksTheIslandBeforeMiddlewareReturnsAndProtectsActionResourcesDuringEmission(): void
    {
        $snapshot = $this->snapshot();
        $logger = $this->createMock(ExceptionTrap::class);
        $logger->expects($this->once())->method('logException');
        $writer = new CollectingSseResponse(exceptions: $logger);
        $service = PolicyFixture::service();
        $request = $this->request
            ->withMethod('POST')
            ->withAttribute('streamAllowed', false)
            ->withHeader('Accept', 'text/event-stream')
            ->withParsedBody(['version' => 1, 'snapshot' => $snapshot, 'action' => 'generate']);
        $response = $this->middleware($service)->process($request, new CallbackHandler(
            fn(ServerRequest $request): Response => new UpdateHandler(
                $this->factory,
                new FailureResponder(),
                $writer,
            )->handle($request, new Response()),
        ));
        $this->assertTrue($service->authorizationChecked());
        $this->assertCount(0, $writer->messages);
        $this->assertSame('text/event-stream; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', (string)$response->getBody());
        $this->assertCount(1, $writer->messages);
        $this->assertSame('failure', $writer->messages[0]['type']);
        $this->assertSame(403, $writer->messages[0]['status']);
        $this->assertStringNotContainsString('private-policy-reason', json_encode(
            $writer->messages,
            JSON_THROW_ON_ERROR,
        ));
    }
}
