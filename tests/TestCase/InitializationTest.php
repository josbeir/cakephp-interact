<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Container\Container;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\ORM\Locator\LocatorInterface;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CallbackRenderer;
use Interact\Test\Fixture\InitializedIsland;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies request-local setup before hydration and authorization on every fresh instance.
 * Tables remain outside signed state and setup does not repeat the mount hook or reset drafts.
 */
class InitializationTest extends TestCase
{
    protected LocatorInterface $previousLocator;

    protected function setUp(): void
    {
        parent::setUp();
        InitializedIsland::$trace = [];
        $this->previousLocator = TableRegistry::getTableLocator();
        $locator = new TableLocator();
        foreach (['Resources', 'Configured'] as $alias) {
            $locator->set($alias, new Table(['alias' => $alias]));
        }

        TableRegistry::setTableLocator($locator);
    }

    protected function tearDown(): void
    {
        TableRegistry::setTableLocator($this->previousLocator);
        InitializedIsland::$trace = [];
        parent::tearDown();
    }

    /**
     * Create a signed runtime using dependency-free island construction.
     */
    protected function runtime(): Runtime
    {
        $registry = new Registry();
        $registry->register(InitializedIsland::class);

        return new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('i', 32)),
            new CallbackRenderer(static fn(): string => '<p>Initialized</p>'),
        );
    }

    /**
     * Keep mounts and updates bound to the same test session.
     */
    protected function context(): IslandContext
    {
        return new IslandContext(
            new ServerRequest(['session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]])]),
            'initialization',
            '/updates',
        );
    }

    public function testSetupPrecedesMountAndRestorationWithoutTransportingTables(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mounted = $runtime->mount('Initialized', ['recordId' => 4], 'setup', $context);
        static::assertSame(
            ['construct', 'initialize:', 'table:Resources', 'authorize:', 'mount', 'view'],
            InitializedIsland::$trace,
        );
        InitializedIsland::$trace = [];
        $updated = $runtime->update([
            'snapshot' => $mounted->snapshot,
            'updates' => ['query' => 'draft'],
            'action' => 'increment',
        ], $context);
        static::assertSame(
            ['construct', 'initialize:', 'table:Resources', 'authorize:draft', 'updated:draft', 'view'],
            InitializedIsland::$trace,
        );
        static::assertSame(5, $updated->state['count']);
        InitializedIsland::$trace = [];
        $synced = $runtime->update(['snapshot' => $updated->snapshot], $context);
        static::assertSame(
            ['construct', 'initialize:', 'table:Resources', 'authorize:draft', 'view'],
            InitializedIsland::$trace,
        );
        static::assertSame('draft', $synced->state['query']);
        $codec = new SnapshotCodec(str_repeat('i', 32));
        $snapshot = $codec->open($synced->snapshot, $context->binding(), $context->scope);
        static::assertSame(
            ['recordId' => 4, 'label' => 'Report', 'count' => 5, 'query' => 'draft'],
            $snapshot['state'],
        );
    }

    public function testContainerDependenciesAreAvailableAndSharedRegistrationsStillInitializeFreshInstances(): void
    {
        $container = new Container();
        $container->addShared(InitializedIsland::class)->addArgument('Configured');
        $registry = new Registry($container);
        $registry->register(InitializedIsland::class);

        $definition = $registry->definition('Initialized');
        static::assertSame([], InitializedIsland::$trace);
        $first = $registry->create($definition);
        $second = $registry->create($definition);
        static::assertNotSame($first, $second);
        $first->authorize($this->context());
        static::assertSame(
            ['construct', 'initialize:', 'construct', 'initialize:', 'table:Configured', 'authorize:'],
            InitializedIsland::$trace,
        );
    }

    public function testPlaceholdersAndTheirLoadingRequestsBothInitializeBeforeAuthorization(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $pending = $runtime->mount('Initialized', ['recordId' => 4], 'setup', $context, new Options('manual'));
        static::assertSame(['construct', 'initialize:', 'table:Resources', 'authorize:'], InitializedIsland::$trace);
        static::assertSame([], $pending->state);
        InitializedIsland::$trace = [];
        $loaded = $runtime->update([
            'snapshot' => $pending->snapshot,
            'initialize' => true,
            'pageQuery' => '?q=latest',
        ], $context);
        static::assertSame(
            ['construct', 'initialize:', 'table:Resources', 'authorize:latest', 'mount', 'view'],
            InitializedIsland::$trace,
        );
        static::assertSame(4, $loaded->state['count']);
    }

    public function testDeniedMountRunsSetupWithoutMountingOrRendering(): void
    {
        $context = $this->context();
        try {
            $this->runtime()->mount('Initialized', ['recordId' => 4], 'setup', new IslandContext(
                $context->request->withAttribute('denied', true),
                $context->scope,
                $context->endpoint,
            ));
            static::fail('Authorization must reject the mount.');
        } catch (ForbiddenException) {
            static::assertSame(
                ['construct', 'initialize:', 'table:Resources', 'authorize:'],
                InitializedIsland::$trace,
            );
        }
    }

    public function testSetupCannotBeCalledAsAnAction(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mounted = $runtime->mount('Initialized', ['recordId' => 4], 'setup', $context);
        InitializedIsland::$trace = [];
        try {
            $runtime->update(['snapshot' => $mounted->snapshot, 'action' => 'initialize'], $context);
            static::fail('Setup must not be callable as an action.');
        } catch (InvalidArgumentException) {
            static::assertSame([], InitializedIsland::$trace);
        }
    }
}
