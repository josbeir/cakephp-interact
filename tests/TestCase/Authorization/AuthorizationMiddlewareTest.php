<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Authorization;

use Authorization\Exception\AuthorizationRequiredException;
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
use Interact\Runtime\Factory;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Authorization\BridgeIsland;
use Interact\Test\Fixture\Authorization\PolicyIsland;
use Interact\Test\Fixture\Authorization\PublicParentIsland;
use Interact\Test\Fixture\Streaming\CollectingSseResponse;
use Interact\Test\TestSuite\Authorization\CallbackHandler;
use Interact\Test\TestSuite\Authorization\PolicyFixture;
use Interact\View\TemplateView;

/**
 * Runs the native Authorization middleware around real island rendering and HTTP update handling.
 */
class AuthorizationMiddlewareTest extends TestCase
{
    protected Factory $factory;

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

        $this->factory = new Factory($registry, new SnapshotCodec(str_repeat('m', 32)));
        $this->request = new ServerRequest([
            'url' => '/_interact/update',
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ])->withAttribute('identity', ['allowed' => true]);
    }

    /**
     * Mount through native middleware and preserve the signed baseline for an independent update.
     */
    protected function snapshot(): string
    {
        $snapshot = '';
        new AuthorizationMiddleware(PolicyFixture::service())->process($this->request, new CallbackHandler(
            function (ServerRequest $request) use (&$snapshot): Response {
                $context = new IslandContext($request, 'default', '/_interact/update');
                $runtime = $this->factory->create(new TemplateView($request), $context);
                $snapshot = $runtime->mount('Policy', [], null, $context)->snapshot;

                return new Response();
            },
        ));

        return $snapshot;
    }

    public function testUsingTheTraitDoesNotImplicitlySkipTheMiddlewareCheck(): void
    {
        $this->expectException(AuthorizationRequiredException::class);
        new AuthorizationMiddleware(PolicyFixture::service())->process($this->request, new CallbackHandler(
            static function (ServerRequest $request): Response {
                new BridgeIsland()->setRequest($request);

                return new Response();
            },
        ));
    }

    public function testPublicIslandsCanExplicitlyAcknowledgeTheRequest(): void
    {
        $service = PolicyFixture::service();
        $response = new AuthorizationMiddleware($service)->process($this->request, new CallbackHandler(
            static function (ServerRequest $request): Response {
                $island = new PublicParentIsland()->setRequest($request);
                $island->authorize(new IslandContext($request, 'default', '/update'));

                return new Response();
            },
        ));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($service->authorizationChecked());
    }

    public function testNativePolicyDenialsBecomeSafe403UpdateResponses(): void
    {
        $snapshot = $this->snapshot();
        $request = $this->request
            ->withMethod('POST')
            ->withAttribute('identity', ['allowed' => false])
            ->withParsedBody(['version' => 1, 'snapshot' => $snapshot, 'action' => 'increment']);
        $response = new AuthorizationMiddleware(PolicyFixture::service())->process($request, new CallbackHandler(
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
        $response = new AuthorizationMiddleware($service)->process($request, new CallbackHandler(
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
