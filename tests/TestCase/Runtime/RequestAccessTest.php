<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Runtime;

use Cake\Container\Container;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Runtime\RedirectResult;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\Runtime\PlainIsland;
use Interact\Test\Fixture\Runtime\RequestIsland;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that native requests are assigned before hooks and refreshed on every instance.
 * Protected request data stays outside snapshots, while sessions retain native CakePHP behavior.
 */
class RequestAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestIsland::$observations = [];
        RequestIsland::$constructed = 0;
    }

    protected function tearDown(): void
    {
        RequestIsland::$observations = [];
        parent::tearDown();
    }

    protected function context(): IslandContext
    {
        return new IslandContext(
            new ServerRequest([
                'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
            ]),
            'requests',
            '/updates',
        );
    }

    protected function runtime(): Runtime
    {
        $container = new Container();
        $container->addShared(RequestIsland::class);

        $registry = new Registry($container);
        $registry->register(RequestIsland::class);

        return new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('r', 32)),
            new CallbackRenderer(
                static fn(): string => '<p>Request</p>',
            ),
        );
    }

    public function testCurrentRequestPrecedesInitializationAndIsNotCachedOrTransported(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Request', [], 'request', $context);
        $this->assertSame(
            ['initialize', 'authorize', 'mount', 'viewData'],
            array_column(RequestIsland::$observations, 'hook'),
        );
        foreach (RequestIsland::$observations as $observation) {
            $this->assertSame($context->request, $observation['request']);
        }

        $initial = RequestIsland::$observations[0]['island'];
        RequestIsland::$observations = [];
        $request = $context->request->withMethod('POST')->withAttribute('requestMarker', 'update');
        $updated = $runtime->update(
            [
                'snapshot' => $mount->snapshot,
                'updates' => ['value' => 'changed'],
                'action' => 'record',
            ],
            new IslandContext($request, $context->scope, $context->endpoint),
        );
        $this->assertSame(
            ['initialize', 'authorize', 'updated', 'action', 'viewData'],
            array_column(RequestIsland::$observations, 'hook'),
        );
        foreach (RequestIsland::$observations as $observation) {
            $this->assertSame($request, $observation['request']);
            $this->assertNotSame($initial, $observation['island']);
        }

        $this->assertSame(2, RequestIsland::$constructed);
        $this->assertSame(['value' => 'changed'], $updated->state);
        $snapshot = new SnapshotCodec(str_repeat('r', 32))->open(
            $updated->snapshot,
            $context->binding(),
            $context->scope,
        );
        $this->assertSame(['value' => 'changed'], $snapshot['state']);
        $this->assertArrayNotHasKey('request', $snapshot);
    }

    public function testPendingAndInitializationRequestsAreAssignedBeforeAnyHooks(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $pending = $runtime->mount('Request', [], 'request', $context, new Options('manual'));
        $this->assertSame(['initialize', 'authorize'], array_column(RequestIsland::$observations, 'hook'));
        foreach (RequestIsland::$observations as $observation) {
            $this->assertSame($context->request, $observation['request']);
        }

        RequestIsland::$observations = [];
        $request = $context->request->withMethod('POST');
        $runtime->update(
            ['snapshot' => $pending->snapshot, 'initialize' => true],
            new IslandContext($request, $context->scope, $context->endpoint),
        );
        $this->assertSame(
            ['initialize', 'authorize', 'mount', 'viewData'],
            array_column(RequestIsland::$observations, 'hook'),
        );
        foreach (RequestIsland::$observations as $observation) {
            $this->assertSame($request, $observation['request']);
        }
    }

    public function testDeniedRequestStillHasContextWithoutReachingApplicationBehavior(): void
    {
        $context = $this->context();
        $request = $context->request->withAttribute('denied', true);
        try {
            $this->runtime()->mount(
                'Request',
                [],
                'request',
                new IslandContext($request, $context->scope, $context->endpoint),
            );
            $this->fail('The denied request must not mount.');
        } catch (ForbiddenException) {
            $this->assertSame(['initialize', 'authorize'], array_column(RequestIsland::$observations, 'hook'));
            foreach (RequestIsland::$observations as $observation) {
                $this->assertSame($request, $observation['request']);
            }
        }
    }

    public function testManualInstancesAcceptAnExplicitNativeRequest(): void
    {
        $island = new PlainIsland();
        $request = $this->context()->request;
        $this->assertSame($island, $island->setRequest($request));
        $this->assertSame($request, $island->getRequest());
    }

    public function testUnconfiguredManualInstanceFailsClearly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Assign a request');
        new PlainIsland()->getRequest();
    }

    public function testActionsCanUseNativeSessionFlashBeforeRedirecting(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Request', [], 'request', $context);
        $response = $runtime->update(['snapshot' => $mount->snapshot, 'action' => 'notify'], $context);
        $this->assertInstanceOf(RedirectResult::class, $response);
        $messages = $context->request->getFlash()->consume('flash');
        $this->assertSame('Saved.', $messages[0]['message'] ?? null);
    }
}
