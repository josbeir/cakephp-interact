<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Authentication;

use Authentication\AuthenticationService;
use Authentication\Authenticator\ResultInterface;
use Authentication\Authenticator\SessionAuthenticator;
use Authentication\Middleware\AuthenticationMiddleware;
use Authorization\IdentityInterface;
use Authorization\Middleware\AuthorizationMiddleware;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Authentication\LifecycleIsland;
use Interact\Test\Fixture\Authentication\PolicyIsland;
use Interact\Test\Fixture\Authentication\ServiceIsland;
use Interact\Test\Fixture\Authorization\Resource;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\TestSuite\Authorization\PolicyFixture;
use Interact\Test\TestSuite\Uploads\UploadStorageTestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exercises real authentication middleware through island lifecycles and policy decoration.
 *
 * Session authentication remains CakePHP-owned; only the terminal HTTP handler is substituted.
 */
class AuthenticationRuntimeTest extends UploadStorageTestCase
{
    protected Runtime $runtime;

    protected SnapshotCodec $snapshots;

    protected function setUp(): void
    {
        parent::setUp();
        LifecycleIsland::$observations = [];
        $registry = new Registry();
        $registry->register(LifecycleIsland::class);

        $this->snapshots = new SnapshotCodec(str_repeat('a', 32));
        $this->runtime = new Runtime(
            $registry,
            $this->snapshots,
            new CallbackRenderer(static fn(): string => '<p>Authenticated island</p>'),
            uploads: $this->uploadFixture->manager(),
        );
    }

    protected function tearDown(): void
    {
        LifecycleIsland::$observations = [];
        parent::tearDown();
    }

    /**
     * Authenticate a deterministic native session and retain the request produced by middleware.
     */
    protected function authenticated(ServerRequest $request, ?int $identifier = 7): ServerRequest
    {
        $session = $request->getSession();
        if ($identifier === null) {
            $session->delete('Auth');
        } else {
            $session->write('Auth', ['id' => $identifier, 'allowed' => true]);
        }

        $service = new AuthenticationService();
        $service->loadAuthenticator(SessionAuthenticator::class);

        $authenticated = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturnCallback(
                static function (ServerRequestInterface $incoming) use (&$authenticated): Response {
                    assert($incoming instanceof ServerRequest);
                    $authenticated = $incoming;

                    return new Response();
                },
            );
        new AuthenticationMiddleware($service)->process($request, $handler);
        assert($authenticated instanceof ServerRequest);

        return $authenticated;
    }

    /**
     * Create a request whose session binding can be retained across island updates.
     */
    protected function request(): ServerRequest
    {
        return new ServerRequest([
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);
    }

    /**
     * Verify that each lifecycle stage sees the current middleware service and identity.
     *
     * @param list<string> $hooks Expected stages of the current interaction.
     */
    protected function assertObservations(array $hooks, ServerRequest $request, ?int $identifier): void
    {
        $this->assertSame($hooks, array_column(LifecycleIsland::$observations, 'hook'));
        foreach (LifecycleIsland::$observations as $observation) {
            $this->assertSame($identifier, $observation['identifier']);
            $this->assertSame($request->getAttribute('authentication'), $observation['service']);
        }
    }

    public function testMountAndUpdatesReadTheCurrentServiceWithoutTransportingIt(): void
    {
        $request = $this->authenticated($this->request());
        $context = new IslandContext($request, 'default', '/update');
        $mounted = $this->runtime->mount('Lifecycle', [], 'identity', $context);
        $this->assertObservations(['initialize', 'authorize', 'mount', 'view'], $request, 7);
        $this->assertSame(['count' => 0], $mounted->state);

        LifecycleIsland::$observations = [];
        $request = $this->authenticated($request->withMethod('POST'), 9);
        $context = new IslandContext($request, 'default', '/update');
        $updated = $this->runtime->update([
            'snapshot' => $mounted->snapshot,
            'updates' => ['count' => 1],
            'action' => 'record',
        ], $context);
        $this->assertObservations(['initialize', 'authorize', 'updated', 'action', 'view'], $request, 9);
        $this->assertSame(['count' => 1], $updated->state);
        $snapshot = $this->snapshots->open($updated->snapshot, $context->binding(), $context->scope);
        $this->assertSame(['count' => 1], $snapshot['state']);
        $this->assertArrayNotHasKey('Authentication', $snapshot);

        LifecycleIsland::$observations = [];
        $request = $this->authenticated($request, 11);
        $synced = $this->runtime->update(
            ['snapshot' => $updated->snapshot],
            new IslandContext($request, 'default', '/update'),
        );
        $this->assertObservations(['initialize', 'authorize', 'view'], $request, 11);
        $this->assertSame(['count' => 1], $synced->state);
    }

    public function testLazyInitializationUsesTheLoadingRequestsAuthentication(): void
    {
        $request = $this->authenticated($this->request());
        $pending = $this->runtime->mount(
            'Lifecycle',
            [],
            'identity',
            new IslandContext($request, 'default', '/update'),
            new Options('manual'),
        );
        $this->assertObservations(['initialize', 'authorize'], $request, 7);
        $this->assertSame([], $pending->state);

        LifecycleIsland::$observations = [];
        $request = $this->authenticated($request->withMethod('POST'), 9);
        $initialized = $this->runtime->update(
            ['snapshot' => $pending->snapshot, 'initialize' => true],
            new IslandContext($request, 'default', '/update'),
        );
        $this->assertObservations(['initialize', 'authorize', 'mount', 'view'], $request, 9);
        $this->assertSame(['count' => 0], $initialized->state);
    }

    public function testNativeIdentityAndResultAccessDoesNotRequireAGuestToLogIn(): void
    {
        $request = $this->authenticated($this->request());
        $island = new ServiceIsland()->setRequest($request);
        $this->assertSame(7, $island->Authentication->getIdentity()?->getIdentifier());
        $this->assertSame($request->getAttribute('authenticationResult'), $island->Authentication->getResult());
        $this->assertTrue($island->Authentication->getResult()?->isValid());

        $guest = $this->authenticated($request, null);
        $island->setRequest($guest);
        $this->assertNull($island->Authentication->getIdentity());
        $this->assertSame(
            ResultInterface::FAILURE_IDENTITY_NOT_FOUND,
            $island->Authentication->getResult()?->getStatus(),
        );
        $mounted = $this->runtime->mount('Lifecycle', [], null, new IslandContext($guest, 'default', '/update'));
        $this->assertObservations(['initialize', 'authorize', 'mount', 'view'], $guest, null);
        $this->assertSame(['count' => 0], $mounted->state);
    }

    public function testAuthorizationDecorationDoesNotReplaceTheExposedAuthenticationService(): void
    {
        $request = $this->authenticated($this->request());
        /** @var mixed $authentication */
        $authentication = $request->getAttribute('authentication');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturnCallback(
                function (ServerRequestInterface $incoming) use ($authentication): Response {
                    assert($incoming instanceof ServerRequest);
                    $island = new PolicyIsland()->setRequest($incoming);
                    $this->assertSame($authentication, $island->Authentication);
                    /** @var mixed $identity */
                    $identity = $incoming->getAttribute('identity');
                    $this->assertInstanceOf(IdentityInterface::class, $identity);
                    $this->assertNotSame($identity, $island->Authentication->getIdentity());
                    $this->assertSame(7, $island->Authentication->getIdentity()?->getIdentifier());
                    $island->Authorization->authorize(new Resource(), 'access');

                    return new Response();
                },
            );
        new AuthorizationMiddleware(PolicyFixture::service())->process($request, $handler);
    }
}
