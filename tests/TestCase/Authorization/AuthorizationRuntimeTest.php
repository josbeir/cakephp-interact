<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Authorization;

use Authorization\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Realtime\LeaseCodec;
use Interact\Realtime\SubscriptionAuthorizerInterface;
use Interact\Realtime\SubscriptionHandler;
use Interact\Realtime\Subscriptions;
use Interact\Runtime\RenderlessResult;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Authorization\PolicyIsland;
use Interact\Test\Fixture\Authorization\PublicParentIsland;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\TestSuite\Authorization\PolicyFixture;
use Interact\Test\TestSuite\Uploads\UploadStorageTestCase;

/**
 * Rechecks real policies at mount, update, initialization, transfer, nesting, and lease boundaries.
 */
class AuthorizationRuntimeTest extends UploadStorageTestCase
{
    protected Registry $registry;

    protected Runtime $runtime;

    protected IslandContext $context;

    /**
     * Keep real signing, sessions, private upload storage, and policy resolution in the tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        PolicyIsland::$trace = [];
        $this->registry = new Registry();
        $this->registry->register(PolicyIsland::class);

        $this->runtime = new Runtime(
            $this->registry,
            new SnapshotCodec(str_repeat('a', 32)),
            new CallbackRenderer(static fn(): string => '<p>Policy island</p>'),
            uploads: $this->uploadFixture->manager(),
        );
        $this->context = new IslandContext(
            PolicyFixture::request(new ServerRequest([
                'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
            ])),
            'default',
            '/update',
            '/subscribe',
        );
    }

    /**
     * Release static observations independently of upload cleanup.
     */
    protected function tearDown(): void
    {
        PolicyIsland::$trace = [];
        parent::tearDown();
    }

    /**
     * Replace the current policy identity without changing the issuing browser session.
     */
    protected function denied(): IslandContext
    {
        return new IslandContext(
            PolicyFixture::request($this->context->request, false),
            'default',
            '/update',
            '/subscribe',
        );
    }

    public function testMountSyncAndActionsKeepTheExistingLifecycleAndSnapshotOwnership(): void
    {
        $mounted = $this->runtime->mount('Policy', [], 'policy', $this->context);
        $this->assertSame(['authorize', 'mount', 'view'], PolicyIsland::$trace);
        $this->assertSame(['subscribe' => false, 'draft' => '', 'count' => 0], $mounted->state);
        $this->assertArrayNotHasKey('Authorization', $mounted->state);

        PolicyIsland::$trace = [];
        $synced = $this->runtime->update([
            'snapshot' => $mounted->snapshot,
            'updates' => ['draft' => 'edited'],
        ], $this->context);
        $this->assertSame(['authorize', 'updated', 'view'], PolicyIsland::$trace);
        $this->assertSame('edited', $synced->state['draft']);
        PolicyIsland::$trace = [];
        $result = $this->runtime->update(['snapshot' => $synced->snapshot, 'action' => 'retain'], $this->context);
        $this->assertInstanceOf(RenderlessResult::class, $result);
        $this->assertSame(['authorize', 'action'], PolicyIsland::$trace);
        $this->assertSame(1, $result->state['count']);
    }

    public function testChangedPermissionsStopEveryUpdateBeforeApplicationWork(): void
    {
        $mounted = $this->runtime->mount('Policy', [], null, $this->context);
        foreach ([null, 'increment', 'retain', 'generate'] as $action) {
            PolicyIsland::$trace = [];
            try {
                $this->runtime->update([
                    'snapshot' => $mounted->snapshot,
                    'updates' => ['draft' => 'untrusted'],
                    'action' => $action,
                ], $this->denied());
                $this->fail('Changed permissions must stop the request.');
            } catch (ForbiddenException) {
                $this->assertSame(['authorize'], PolicyIsland::$trace);
            }
        }
    }

    public function testDeniedInitialMountDoesNotReadViewDataOrRunMount(): void
    {
        try {
            $this->runtime->mount('Policy', [], null, $this->denied());
            $this->fail('A denied mount must not render.');
        } catch (ForbiddenException) {
            $this->assertSame(['authorize'], PolicyIsland::$trace);
        }
    }

    public function testDeferredInitializationRechecksPermissionBeforeMounting(): void
    {
        $pending = $this->runtime->mount('Policy', [], null, $this->context, new Options('manual'));
        $this->assertSame(['authorize'], PolicyIsland::$trace);
        PolicyIsland::$trace = [];
        try {
            $this->runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $this->denied());
            $this->fail('A pending ticket is not continuing authorization.');
        } catch (ForbiddenException) {
            $this->assertSame(['authorize'], PolicyIsland::$trace);
        }

        PolicyIsland::$trace = [];
        $this->runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $this->context);
        $this->assertSame(['authorize', 'mount', 'view'], PolicyIsland::$trace);
    }

    public function testActionArgumentsRequireTheirOwnResourceCheck(): void
    {
        $mounted = $this->runtime->mount('Policy', [], null, $this->context);
        PolicyIsland::$trace = [];
        try {
            $this->runtime->update([
                'snapshot' => $mounted->snapshot,
                'action' => 'other',
                'args' => [false],
            ], $this->context);
            $this->fail('An allowed island must not grant access to an action argument.');
        } catch (ForbiddenException) {
            $this->assertSame(['authorize'], PolicyIsland::$trace);
        }
    }

    public function testDeniedUploadsDoNotCreatePrivateStorage(): void
    {
        $mounted = $this->runtime->mount('Policy', [], null, $this->context);
        $denied = $this->denied();
        $context = new IslandContext(
            $denied->request->withUploadedFiles(['file' => $this->incoming()]),
            'default',
            '/update',
        );
        try {
            $this->runtime->update([
                'snapshot' => $mounted->snapshot,
                'upload' => ['kind' => 'stage', 'field' => 'file'],
            ], $context);
            $this->fail('A denied upload must not stage private files.');
        } catch (ForbiddenException) {
            $this->assertDirectoryDoesNotExist($this->directory);
        }
    }

    public function testPublicParentCannotSkipAuthorizationForItsProtectedChild(): void
    {
        $this->registry->register(PublicParentIsland::class);
        $context = $this->denied();
        $this->runtime = new Runtime(
            $this->registry,
            new SnapshotCodec(str_repeat('a', 32)),
            new CallbackRenderer(fn(): string => $this->runtime->mount(
                'Policy',
                [],
                'child',
                $context,
            )->html),
        );
        $this->expectException(ForbiddenException::class);
        $this->runtime->mount('PublicParent', [], null, $context);
    }

    public function testLeaseRenewalRechecksPolicyWithoutRunningApplicationHooks(): void
    {
        $leases = new LeaseCodec(str_repeat('l', 32));
        $subscriptions = new Subscriptions($leases);
        $this->runtime->attachSubscriptions($subscriptions);
        $mounted = $this->runtime->mount('Policy', ['subscribe' => true], null, $this->context);
        $this->assertNotNull($mounted->realtime);
        PolicyIsland::$trace = [];
        $provider = $this->createMock(SubscriptionAuthorizerInterface::class);
        $provider->expects($this->never())->method('authorize');
        $request = $this->denied()
            ->request
            ->withMethod('POST')
            ->withParsedBody([
                'version' => 1,
                'leases' => [$mounted->id => $mounted->realtime['lease']],
            ]);
        $response = new SubscriptionHandler($this->registry, $leases, $provider, $subscriptions)->handle(
            $request,
            new Response(),
        );
        $this->assertSame(['authorize'], PolicyIsland::$trace);
        $this->assertStringContainsString('Subscription authorization failed.', (string)$response->getBody());
        $this->assertStringNotContainsString('private-policy-reason', (string)$response->getBody());
    }
}
