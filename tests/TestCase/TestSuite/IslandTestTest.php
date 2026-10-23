<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\TestSuite;

use App\Island\CounterIsland as QuickstartCounter;
use Cake\Core\Configure;
use Cake\Core\ConsoleApplicationInterface;
use Cake\Core\HttpApplicationInterface;
use Cake\Core\Plugin;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventManager;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Closure;
use Commerce\Island\Article\EditorIsland as PluginEditor;
use Interact\IslandContext;
use Interact\Runtime\RenderlessResult;
use Interact\Security\InvalidSnapshotException;
use Interact\Test\Fixture\Testing\Application;
use Interact\Test\Fixture\Testing\ProbeIsland;
use Interact\Test\Fixture\Testing\ProbeService;
use Interact\TestSuite\IslandTest;
use Interact\TestSuite\IslandTestTrait;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TestApp\Island\Article\EditorIsland;
use TestApp\Island\CounterIsland;

/**
 * Exercises the public fluent API against application services, signed state, and native templates.
 * Real SQLite persistence and request attributes preserve application ownership boundaries.
 */
class IslandTestTest extends TestCase
{
    use IntegrationTestTrait;

    use IslandTestTrait {
        IslandTestTrait::createApp as protected createConfiguredApplication;
    }

    protected ?ConsoleApplicationInterface $applicationOverride = null;

    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.paths.templates', [
            APP . 'templates/',
            dirname(__DIR__, 2) . '/Fixture/views/templates/',
        ]);
        $this->configApplication(Application::class, [CONFIG]);
        $connection = ConnectionManager::get('default');
        static::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->begin();
    }

    protected function tearDown(): void
    {
        $this->connection->rollback();
        parent::tearDown();
    }

    /**
     * Allow one test to exercise the documented unsupported application boundary.
     */
    protected function createApp(): HttpApplicationInterface|ConsoleApplicationInterface
    {
        return $this->applicationOverride ?? $this->createConfiguredApplication();
    }

    public function testMountsNamesAndClassReferencesWithRealApplicationViews(): void
    {
        $this->testIsland(CounterIsland::class, ['label' => 'Initial counter', 'initial' => 2])
            ->assertSet('count', 2)
            ->call('adjust', 5)
            ->assertSet('count', 7)
            ->assertSee('Initial counter');
        $this->testIsland('Counter', ['initial' => 8])->assertSet('count', 8);
        $this->testIsland('Testing', ['label' => 'A fixture'])
            ->assertSee('A fixture')
            ->assertSee('application view')
            ->assertSee('real service');
    }

    public function testTheDocumentedQuickstartCounterTestWorks(): void
    {
        Configure::write('App.namespace', 'App');
        Configure::write('App.paths.templates', [dirname(__DIR__, 2) . '/docs/app/templates/']);
        $this->testIsland(QuickstartCounter::class)
            ->assertSet('count', 0)
            ->call('increment')
            ->assertSet('count', 1)
            ->assertSee('Count: 1');
    }

    public function testDefaultApplicationConfigurationNeedsNoFactorySetup(): void
    {
        $this->cleanupContainer();
        $this->testIsland('Counter', ['initial' => 3])->call('adjust', 1)->assertSet('count', 4);
    }

    public function testTheTraitComposesWithCakeHttpIntegrationTests(): void
    {
        $this->get('/playground');
        $this->assertResponseOk();
        $this->testIsland('Counter')->call('adjust', 5)->assertSet('count', 5);
        $this->get('/playground');
        $this->assertResponseOk();
    }

    public function testFreshInstancesRestoreStateWithoutRepeatingMount(): void
    {
        $probe = new ProbeService();
        $this->mockService(ProbeService::class, static fn(): ProbeService => $probe);
        $test = $this->testIsland(ProbeIsland::class)->assertSet('count', 5)->assertSee('Construction: 1');
        $identity = $test->result()->id;
        $test->call('record', 3)->assertSet('count', 8)->assertSee('Construction: 2');
        $test->sync()->assertSet('count', 8)->assertSee('Construction: 3');
        static::assertSame($identity, $test->result()->id);
        static::assertSame(3, $probe->constructions);
        static::assertSame(3, $probe->renders);
        static::assertSame('GET', $probe->requests[0]->getMethod());
        static::assertFalse($probe->requests[0]->hasHeader('Interact'));
        static::assertSame('POST', $probe->requests[1]->getMethod());
        static::assertSame('true', $probe->requests[1]->getHeaderLine('Interact'));
        static::assertSame('/_interact/update', $probe->requests[1]->getUri()->getPath());
        static::assertSame('record', $probe->requests[1]->getData('action'));
        static::assertSame('application/json', $probe->requests[1]->getHeaderLine('Content-Type'));
        static::assertStringContainsString('data-interact-preserve', $test->result()->html);
    }

    public function testSetAndFillSendImmediateDistinctBatchesOfWritablePaths(): void
    {
        $this->testIsland('Testing')
            ->set('title', 'Changed')
            ->assertSet('title', 'Changed')
            ->assertSet('paths', ['title'])
            ->assertSet('count', 6)
            ->fill(['title' => 'Batched', 'data.rows.0.body' => 'Nested edit'])
            ->assertSet('title', 'Batched')
            ->assertSet('data.rows.0.body', 'Nested edit')
            ->assertSet('paths', ['title', 'data.rows.0.body'])
            ->assertSet('count', 7);
    }

    public function testStateAssertionsPreserveJsonScalarDistinctionsAndEscaping(): void
    {
        $this->testIsland('Testing')
            ->assertSet('data.nullable', null)
            ->assertSet('data.enabled', false)
            ->assertSet('data.zero', 0)
            ->assertSet('data.empty', '')
            ->set('title', '<script>alert("test")</script>')
            ->assertSee('<script>alert("test")</script>')
            ->assertDontSee('Never rendered')
            ->assertRendered()
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertNoDownload();
    }

    public function testValidationFieldsAreResponseLocalAndSuccessEventsAreSuppressed(): void
    {
        $test = $this->testIsland('Testing')
            ->set('title', '')
            ->call('validate')
            ->assertHasErrors()
            ->assertHasErrors(['title', 'data.rows.0.body'])
            ->assertHasNoErrors(['other'])
            ->assertNotDispatched('suppressed');
        static::assertSame(['Required.'], $test->result()->errors['title']);
        $test->set('title', 'Valid')->call('validate')->assertHasNoErrors();
    }

    public function testEveryMatchingEventDetailCanBeAssertedWithoutAccumulatingOldEvents(): void
    {
        $this->testIsland('Testing')
            ->call('record')
            ->assertDispatched('recorded')
            ->assertDispatched('recorded', ['id' => 1])
            ->assertDispatched('recorded', ['id' => 2])
            ->assertDispatched('empty-event', [])
            ->assertNotDispatched('other')
            ->sync()
            ->assertNotDispatched('recorded');
    }

    public function testRenderlessActionsPreserveStateAndCanResumeRendering(): void
    {
        $probe = new ProbeService();
        $this->mockService(ProbeService::class, static fn(): ProbeService => $probe);
        $test = $this->testIsland('Testing')
            ->call('quiet')
            ->assertNotRendered()
            ->assertSet('count', 6)
            ->assertDispatched('recorded')
            ->assertHasNoErrors();
        static::assertInstanceOf(RenderlessResult::class, $test->result());
        static::assertSame(1, $probe->renders);
        $test->sync()->assertRendered()->assertSet('count', 6)->assertSee('Count: 6');
    }

    public function testValidationCleanupForcesRenderingBeforeRenderlessActionsResume(): void
    {
        $this->testIsland('Testing')
            ->set('title', '')
            ->call('validate')
            ->assertHasErrors()
            ->call('quiet')
            ->assertRendered()
            ->assertHasNoErrors()
            ->call('quiet')
            ->assertNotRendered();
    }

    public function testDownloadsAreInstructionsAndRedirectsEndTheChain(): void
    {
        $test = $this->testIsland('Testing')
            ->call('download')
            ->assertDownload('/report.csv')
            ->assertRendered()
            ->assertNoRedirect()
            ->sync()
            ->assertNoDownload()
            ->call('leave')
            ->assertRedirect('/done')
            ->assertNotRendered();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('redirect ended');
        $test->sync();
    }

    public function testAuthorizationRunsOnInitialMounts(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->testIsland('Testing', request: new ServerRequest()->withAttribute('allowed', false));
    }

    public function testReplacementRequestsRecheckAuthorizationBeforeRendering(): void
    {
        $probe = new ProbeService();
        $this->mockService(ProbeService::class, static fn(): ProbeService => $probe);
        $request = new ServerRequest();
        $test = $this->testIsland('Testing', request: $request);
        $this->expectException(ForbiddenException::class);
        try {
            $test->withRequest($request->withAttribute('allowed', false))->call('record');
        } finally {
            static::assertSame(1, $probe->renders);
        }
    }

    public function testChangedSessionBindingsAreNotResignedOrBypassed(): void
    {
        $session = $this->createStub(Session::class);
        $session->method('start')->willReturn(true);
        $session->method('id')->willReturn('another-session');
        $test = $this->testIsland('Testing');
        $this->expectException(InvalidSnapshotException::class);
        $test->withRequest(new ServerRequest(['session' => $session]))->sync();
    }

    public function testApplicationExceptionsAndUndeclaredActionsReachPhpunit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Application action failed.');
        $this->testIsland('Testing')->call('explode');
    }

    /**
     * Supply writes that must remain subject to the runtime's writable-state contract.
     *
     * @return iterable<string, array{string, mixed}> Invalid writable paths or values.
     */
    public static function invalidWrites(): iterable
    {
        yield 'prop' => ['label', 'Changed'];
        yield 'locked' => ['count', 99];
        yield 'unknown' => ['unknown', 'value'];
        yield 'type' => ['title', false];
        yield 'scalar traversal' => ['title.child', 'value'];
        yield 'unsafe path' => ['data.__proto__', 'value'];
    }

    #[DataProvider('invalidWrites')]
    public function testInvalidWritesUseNormalRuntimeValidation(string $path, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->testIsland('Testing')->set($path, $value);
    }

    public function testUndeclaredActionsUseNormalRuntimeValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->testIsland('Testing')->call('mount');
    }

    public function testTypedActionArgumentsAreNotCoerced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->testIsland('Testing')->call('record', '5');
    }

    public function testPluginSelectorsAndReferencesRetainApplicationOverrides(): void
    {
        /** @var mixed $paths */
        $paths = Configure::read('App.paths.templates');
        static::assertIsArray($paths);
        Configure::write('App.paths.templates', [
            dirname(__DIR__, 2) . '/Fixture/views/overrides/',
            ...$paths,
        ]);
        $this->testIsland('Commerce.Article/Editor')
            ->assertSee('Application override: 0')
            ->call('increment')
            ->assertSee('Application override: 1');
        $this->testIsland(PluginEditor::class)->assertSet('count', 0);
    }

    public function testTheApplicationOwnsAuthorizationValidationAndPersistence(): void
    {
        $request = new ServerRequest()->withAttribute('identity', ['role' => 'editor', 'id' => 1]);
        $this->testIsland(EditorIsland::class, ['articleId' => 1], $request)
            ->set('title', 'Saved through fluent testing')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true)
            ->assertDispatched('article-saved', ['id' => 1]);
        static::assertSame(
            'Saved through fluent testing',
            $this->getTableLocator()->get('Articles')->get(1)->get('title'),
        );
    }

    public function testCleanupRestoresGlobalsAndDropsApplicationMocks(): void
    {
        $plugins = Plugin::getCollection();
        $routes = Router::getRouteCollection();
        $events = EventManager::instance();
        $request = new ServerRequest(['url' => '/original']);
        Router::setRequest($request);
        $this->mockService(ProbeService::class, static fn(): ProbeService => new ProbeService('mocked service'));
        $this->testIsland('Testing')->assertSee('mocked service');
        $this->cleanupIslandTests();
        $this->cleanupContainer();
        static::assertSame($plugins, Plugin::getCollection());
        static::assertSame($routes, Router::getRouteCollection());
        static::assertSame($events, EventManager::instance());
        static::assertSame($request, Router::getRequest());
        $this->configApplication(Application::class, [CONFIG]);
        $this->testIsland('Testing')->assertSee('real service')->assertDontSee('mocked service');
    }

    public function testApplicationsWithoutAContainerFailClearly(): void
    {
        $this->applicationOverride = $this->createStub(ConsoleApplicationInterface::class);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('application with a CakePHP container');
        $this->testIsland('Counter');
    }

    public function testExplicitConstructionSupportsCustomContexts(): void
    {
        $request = new ServerRequest(['url' => 'http://localhost/playground']);
        $context = new IslandContext($request, 'custom-scope', '/custom-update');
        $test = new IslandTest($this->getIslandTestFactory(), $context, 'Testing');
        $test->call('record')->assertSet('count', 6);
        static::assertStringContainsString('data-interact-endpoint="/custom-update"', $test->result()->html);
    }

    /**
     * Supply assertion mismatches, including missing values and stale HTML responses.
     *
     * @return iterable<string, array{\Closure(\Interact\TestSuite\IslandTest): \Interact\TestSuite\IslandTest, string|null}> Assertions and prerequisite action.
     */
    public static function failingAssertions(): iterable
    {
        yield 'missing value differs from null' => [
            static fn(IslandTest $test): IslandTest => $test->assertSet('missing', null),
            null,
        ];
        yield 'cannot traverse a scalar' => [
            static fn(IslandTest $test): IslandTest => $test->assertSet('title.child', null),
            null,
        ];
        yield 'false differs from zero' => [
            static fn(IslandTest $test): IslandTest => $test->assertSet('data.enabled', 0),
            null,
        ];
        yield 'missing text' => [static fn(IslandTest $test): IslandTest => $test->assertSee('Never rendered'), null];
        yield 'unexpected text' => [
            static fn(IslandTest $test): IslandTest => $test->assertDontSee('Initial title'),
            null,
        ];
        yield 'missing errors' => [static fn(IslandTest $test): IslandTest => $test->assertHasErrors(), null];
        yield 'missing error field' => [
            static fn(IslandTest $test): IslandTest => $test->assertHasErrors(['other']),
            'errors',
        ];
        yield 'unexpected errors' => [static fn(IslandTest $test): IslandTest => $test->assertHasNoErrors(), 'errors'];
        yield 'unexpected field error' => [
            static fn(IslandTest $test): IslandTest => $test->assertHasNoErrors(['title']),
            'errors',
        ];
        yield 'missing event' => [static fn(IslandTest $test): IslandTest => $test->assertDispatched('other'), null];
        yield 'incorrect detail' => [
            static fn(IslandTest $test): IslandTest => $test->assertDispatched('recorded', ['id' => '1']),
            'record',
        ];
        yield 'unexpected event' => [
            static fn(IslandTest $test): IslandTest => $test->assertNotDispatched('recorded'),
            'record',
        ];
        yield 'missing redirect' => [static fn(IslandTest $test): IslandTest => $test->assertRedirect('/done'), null];
        yield 'incorrect redirect' => [
            static fn(IslandTest $test): IslandTest => $test->assertRedirect('/other'),
            'leave',
        ];
        yield 'unexpected redirect' => [static fn(IslandTest $test): IslandTest => $test->assertNoRedirect(), 'leave'];
        yield 'missing download' => [
            static fn(IslandTest $test): IslandTest => $test->assertDownload('/report.csv'),
            null,
        ];
        yield 'unexpected download' => [
            static fn(IslandTest $test): IslandTest => $test->assertNoDownload(),
            'download',
        ];
        yield 'renderless is not rendered' => [
            static fn(IslandTest $test): IslandTest => $test->assertRendered(),
            'quiet',
        ];
        yield 'redirect is not rendered' => [
            static fn(IslandTest $test): IslandTest => $test->assertRendered(),
            'leave',
        ];
        yield 'ordinary response is rendered' => [
            static fn(IslandTest $test): IslandTest => $test->assertNotRendered(),
            null,
        ];
        yield 'stale positive html assertion' => [
            static fn(IslandTest $test): IslandTest => $test->assertSee('Initial title'),
            'quiet',
        ];
        yield 'stale negative html assertion' => [
            static fn(IslandTest $test): IslandTest => $test->assertDontSee('Never rendered'),
            'quiet',
        ];
        yield 'redirect html assertion' => [
            static fn(IslandTest $test): IslandTest => $test->assertSee('Initial title'),
            'leave',
        ];
    }

    /**
     * Prove mismatched expectations fail rather than silently accepting stale or absent data.
     *
     * @param \Closure(\Interact\TestSuite\IslandTest): \Interact\TestSuite\IslandTest $assertion Public fluent assertion.
     */
    #[DataProvider('failingAssertions')]
    public function testAssertionsRejectMismatches(Closure $assertion, ?string $action): void
    {
        $test = $this->testIsland('Testing');
        match ($action) {
            null => $test,
            'errors' => $test->set('title', '')->call('validate'),
            default => $test->call($action),
        };
        $this->expectException(AssertionFailedError::class);
        $assertion($test);
    }
}
