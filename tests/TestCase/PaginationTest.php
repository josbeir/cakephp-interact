<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\Paging\NumericPaginator;
use Cake\Datasource\RepositoryInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateHandler;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Pagination\Configuration;
use Interact\Pagination\PagePath;
use Interact\Pagination\Pagination;
use Interact\Runtime\Factory;
use Interact\Runtime\Runtime;
use Interact\Runtime\SnapshotData;
use Interact\Runtime\Values;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CounterIsland;
use Interact\Test\Fixture\Pagination\InvalidAliasIsland;
use Interact\Test\Fixture\Pagination\ListingIsland;
use Interact\Test\Fixture\Pagination\NullablePagingIsland;
use Interact\Test\Fixture\Pagination\PageIsland;
use Interact\Test\Fixture\Pagination\PrivatePageIsland;
use Interact\Test\Fixture\Pagination\StateOnlyIsland;
use Interact\Test\Fixture\Pagination\TwoListingsIsland;
use Interact\View\TemplateView;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies native pagination through real SQLite queries, isolated templates, and signed updates.
 * Results and normalized state must agree on initial renders, updates, and history restoration.
 */
class PaginationTest extends TestCase
{
    protected Runtime $runtime;

    protected IslandContext $context;

    protected SnapshotCodec $codec;

    protected TemplateView $view;

    /**
     * Build the normal runtime with native views and explicit fixture registrations.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.paths.templates', [dirname(__DIR__) . '/Fixture/views/templates/']);
        $request = new ServerRequest([
            'url' => '/catalog/7?utm=mail',
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);
        $this->context = new IslandContext($request, 'default', '/_interact/update');
        $registry = new Registry();
        $registry->register(PageIsland::class, 'Paging');
        $registry->register(ListingIsland::class, 'Listing');
        $registry->register(PrivatePageIsland::class, 'PrivatePaging');
        $registry->register(StateOnlyIsland::class, 'StateOnly');
        $registry->register(TwoListingsIsland::class, 'TwoListings');

        $this->codec = new SnapshotCodec(str_repeat('p', 32));
        $this->view = new TemplateView($request);
        $this->runtime = new Factory($registry, $this->codec)->create($this->view, $this->context);
    }

    public function testNativeConfigurationAndScopedQueriesKeepRowsOutOfSnapshots(): void
    {
        $initial = $this->runtime->mount('Paging', ['settings' => ['limit' => 3]], null, $this->context);
        static::assertStringContainsString('1–3 of 150', $initial->html);
        static::assertStringContainsString('href="/catalog/7?utm=mail&amp;p=2"', $initial->html);
        static::assertSame(
            [
                'paging' => ['page' => 1, 'sort' => '', 'direction' => 'asc'],
                'search' => '',
                'settings' => ['limit' => 3],
                'twice' => false,
                'allowed' => true,
            ],
            $initial->state,
        );
        $signed = $this->codec->open($initial->snapshot, $this->context->binding(), 'default');
        static::assertSame('/catalog/7', $signed['pagePath']);
        static::assertIsArray($signed['state']);
        static::assertArrayNotHasKey('rows', $signed['state']);
        static::assertStringNotContainsString('Alpine', $initial->html);
    }

    public function testClampingUpdatesHtmlStateSnapshotAndOriginalPageLinksTogether(): void
    {
        $initial = $this->runtime->mount('Paging', [], null, $this->context);
        $request = $this->context->request->withUri($this->context->request->getUri()->withPath('/_interact/update'));
        $updated = $this->runtime->update(
            ['snapshot' => $initial->snapshot, 'updates' => ['paging.page' => 999], 'pageQuery' => '?utm=mail'],
            new IslandContext($request, 'default', '/_interact/update'),
        );
        static::assertSame(30, Values::map($updated->state['paging'])['page']);
        static::assertStringContainsString('Accepted page 30.', $updated->html);
        static::assertStringContainsString('146–150 of 150', $updated->html);
        static::assertStringContainsString('/catalog/7?utm=mail&amp;p=29', $updated->html);
        $signed = $this->codec->open($updated->snapshot, $this->context->binding(), 'default');
        static::assertIsArray($signed['state']);
        static::assertSame(30, Values::map($signed['state']['paging'])['page']);
        $empty = $this->runtime->update([
            'snapshot' => $updated->snapshot,
            'updates' => ['search' => 'no such resource'],
        ], $this->context);
        static::assertSame(1, Values::map($empty->state['paging'])['page']);
        static::assertStringContainsString('0–0 of 0', $empty->html);
        $negative = $this->runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => ['paging.page' => -5],
        ], $this->context);
        static::assertSame(1, Values::map($negative->state['paging'])['page']);
    }

    /**
     * Check every update path rejects an old signed identity before constructing application code.
     *
     * @param array<string, mixed> $input Valid operation paired with a pre-upgrade snapshot.
     */
    #[DataProvider('legacyOperations')]
    public function testLegacySnapshotsRequireReloadBeforeApplicationCodeRuns(array $input): void
    {
        $mount = $this->runtime->mount(
            'Paging',
            [],
            null,
            $this->context,
            new Options(isset($input['initialize']) ? 'manual' : 'eager'),
        );
        $data = $this->codec->open($mount->snapshot, $this->context->binding(), $this->context->scope);
        unset($data['pagePath']);
        $legacy = $this->codec->seal($data, $this->context->binding(), $this->context->scope);
        $registry = $this->createMock(Registry::class);
        $registry->expects(static::never())->method('definition');
        $registry->expects(static::never())->method('create');
        $handler = new UpdateHandler(new Factory($registry, $this->codec), new FailureResponder());
        $request = $this->context
            ->request
            ->withMethod('POST')
            ->withUri($this->context->request->getUri()->withPath('/_interact/update'))
            ->withParsedBody(['version' => 1, 'snapshot' => $legacy] + $input);
        $response = $handler->handle($request, new Response());
        static::assertSame(409, $response->getStatusCode());
        static::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        static::assertJsonStringEqualsJsonString(
            '{"version":1,"error":{"code":"invalid_snapshot","message":"This page can no longer update. Preserve your draft, then reload."}}',
            (string)$response->getBody(),
        );
        $fresh = $this->runtime->mount('Paging', [], null, $this->context);
        $updated = $this->runtime->update(
            ['snapshot' => $fresh->snapshot, 'updates' => ['paging.page' => 2]],
            new IslandContext($request, 'default', '/_interact/update'),
        );
        static::assertStringContainsString('/catalog/7?', $updated->html);
        static::assertStringNotContainsString('href="/_interact/update', $updated->html);
    }

    /**
     * Supply ordinary, renderless, history, and deferred-initialization update envelopes.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function legacyOperations(): iterable
    {
        yield 'synchronization' => [['updates' => ['paging.page' => 2]]];
        yield 'renderless action' => [['action' => 'changePage', 'args' => [2]]];
        yield 'history restoration' => [['urlRestore' => true, 'updates' => ['paging.page' => 2, 'search' => '']]];
        yield 'deferred initialization' => [['initialize' => true]];
    }

    public function testExplicitLimitAndSortStateOverrideConfigurationAndStayAllowlisted(): void
    {
        $initial = $this->runtime->mount('Listing', ['settings' => ['limit' => 2]], null, $this->context);
        static::assertStringContainsString('1–5 of 150', $initial->html);
        $sorted = $this->runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => ['paging.sort' => 'title', 'paging.direction' => 'DESC', 'paging.limit' => 500],
        ], $this->context);
        static::assertSame(10, Values::map($sorted->state['paging'])['limit']);
        static::assertSame('desc', Values::map($sorted->state['paging'])['direction']);
        static::assertSame('title', Values::map($sorted->state['paging'])['sort']);
        static::assertStringContainsString('1–10 of 150', $sorted->html);
        static::assertStringContainsString('order=title', $sorted->html);
        $invalid = $this->runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => ['paging.sort' => 'Resources.id', 'paging.direction' => 'sql'],
        ], $this->context);
        static::assertSame('', Values::map($invalid->state['paging'])['sort']);
        static::assertSame('asc', Values::map($invalid->state['paging'])['direction']);
        static::assertStringContainsString('1–5 of 150', $invalid->html);
    }

    public function testPrefixesHistoryAndDeferredInitializationUseTheSamePagingContract(): void
    {
        $pending = $this->runtime->mount(
            'Listing',
            [],
            'left',
            $this->context,
            new Options('manual', urlPrefix: 'left'),
        );
        static::assertStringNotContainsString('Accepted page', $pending->html);
        $mounted = $this->runtime->update([
            'snapshot' => $pending->snapshot,
            'initialize' => true,
            'pageQuery' => '?left_p=2&utm=mail&right_p=4',
        ], $this->context);
        static::assertSame(2, Values::map($mounted->state['paging'])['page']);
        static::assertStringContainsString('/catalog/7?utm=mail&amp;right_p=4&amp;left_p=3', $mounted->html);
        $restored = $this->runtime->update([
            'snapshot' => $mounted->snapshot,
            'urlRestore' => true,
            'updates' => [
                'paging.page' => 3,
                'search' => '',
                'paging.sort' => '',
                'paging.direction' => 'asc',
                'paging.limit' => 5,
            ],
        ], $this->context);
        static::assertSame(3, Values::map($restored->state['paging'])['page']);
        static::assertStringContainsString('11–15 of 150', $restored->html);
        $renderless = $this->runtime->update([
            'snapshot' => $restored->snapshot,
            'action' => 'changePage',
            'args' => [999],
        ], $this->context);
        static::assertNull($renderless->payload()['html']);
        static::assertSame(999, Values::map($renderless->state['paging'])['page']);
        static::assertSame(
            30,
            Values::map(
                $this->runtime->update(['snapshot' => $renderless->snapshot], $this->context)->state['paging'],
            )['page'],
        );
    }

    public function testDeletingTheLastPageClampsTheNextRefreshWithoutAnApplicationAction(): void
    {
        $mount = $this->runtime->mount('Paging', [], null, $this->context);
        $last = $this->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['paging.page' => 30],
        ], $this->context);
        $connection = ConnectionManager::get('default');
        static::assertInstanceOf(Connection::class, $connection);
        $connection->begin();
        try {
            $connection->execute(
                "DELETE FROM resources WHERE id IN (SELECT id FROM resources WHERE category = 'cakephp' ORDER BY id DESC LIMIT 5)",
            );
            $refresh = $this->runtime->update(['snapshot' => $last->snapshot], $this->context);
            static::assertSame(29, Values::map($refresh->state['paging'])['page']);
            static::assertStringContainsString('141–145 of 145', $refresh->html);
        } finally {
            $connection->rollback();
        }
    }

    public function testAuthorizesEveryRequestBeforeQuerying(): void
    {
        $mount = $this->runtime->mount('Paging', [], null, $this->context);
        $this->expectException(ForbiddenException::class);
        $this->runtime->update(
            ['snapshot' => $mount->snapshot],
            new IslandContext($this->context->request->withAttribute('denied', true), 'default', '/_interact/update'),
        );
    }

    public function testSeveralQueriesAgainstTheSameTableKeepIndependentStateAndCompleteLinkMetadata(): void
    {
        $mount = $this->runtime->mount('TwoListings', [], null, $this->context);
        static::assertStringContainsString('First 1–5 of 150', $mount->html);
        static::assertStringContainsString('Second 1–2 of 30', $mount->html);
        static::assertSame(2, substr_count($mount->html, 'data-interact-pagination='));
        static::assertStringContainsString('&quot;paging.page&quot;:2', $mount->html);
        static::assertStringContainsString('&quot;other.page&quot;:2', $mount->html);
        $updated = $this->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['paging.page' => 3, 'other.page' => 2],
        ], $this->context);
        static::assertStringContainsString('First 11–15 of 150', $updated->html);
        static::assertStringContainsString('Second 3–4 of 30', $updated->html);
        static::assertStringContainsString('other_page=2&amp;p=4', $updated->html);
        static::assertStringContainsString('other_page=3&amp;p=3', $updated->html);
        $bounded = $this->runtime->update([
            'snapshot' => $updated->snapshot,
            'updates' => ['other.page' => 999],
        ], $this->context);
        static::assertSame(3, Values::map($bounded->state['paging'])['page']);
        static::assertSame(15, Values::map($bounded->state['other'])['page']);
        static::assertStringContainsString('other_page=15&amp;p=4', $bounded->html);
    }

    public function testRejectsSeveralDatasetsWithinOneIsland(): void
    {
        $this->expectException(LogicException::class);
        $this->runtime->mount('Paging', ['twice' => true], null, $this->context);
    }

    public function testRejectsNativeScopeInFavorOfExplicitIslandPrefixes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runtime->mount('Paging', ['settings' => ['scope' => 'articles']], null, $this->context);
    }

    public function testNativeClassAliasesAndRepositoriesAreSupported(): void
    {
        $configuration = new Configuration(new Definition(PageIsland::class, 'Paging'));
        static::assertInstanceOf(NumericPaginator::class, $configuration->paginator([
            'className' => 'Numeric',
        ]));
        $this->expectException(InvalidArgumentException::class);
        $configuration->paginator(['className' => 'Simple']);
    }

    public function testRejectsMissingPageDeclarations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration(new Definition(CounterIsland::class))->select(new CounterIsland(), null);
    }

    public function testPrivatePagingIsWritableWithoutOwningAnyPagingQueryParameter(): void
    {
        $fields = new Definition(PrivatePageIsland::class)->properties['paging']->bindings();
        static::assertSame([], $fields);
        $mount = $this->runtime->mount('PrivatePaging', [], null, $this->context);
        static::assertArrayNotHasKey('page', $mount->url);
        $updated = $this->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['paging.page' => 2, 'search' => 'CakePHP'],
            'pageQuery' => '?utm=mail',
        ], $this->context);
        static::assertSame(2, Values::map($updated->state['paging'])['page']);
        static::assertStringContainsString('6–10 of 150', $updated->html);
        static::assertStringContainsString('/catalog/7?utm=mail&amp;q=CakePHP#interact-pagination-', $updated->html);
        static::assertStringNotContainsString('&amp;p=', $updated->html);
    }

    public function testOmittingUrlKeepsAllPagingStateInTheSnapshot(): void
    {
        $mount = $this->runtime->mount('StateOnly', [], null, $this->context);
        static::assertSame([], $mount->url);
        $updated = $this->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => [
                'paging.page' => 3,
                'paging.limit' => 7,
                'paging.sort' => 'title',
                'paging.direction' => 'desc',
            ],
            'pageQuery' => '?utm=mail',
        ], $this->context);
        static::assertSame([], $updated->url);
        static::assertSame(3, Values::map($updated->state['paging'])['page']);
        static::assertSame(7, Values::map($updated->state['paging'])['limit']);
        static::assertSame('desc', Values::map($updated->state['paging'])['direction']);
        static::assertStringContainsString('15–21 of 150', $updated->html);
        static::assertStringNotContainsString('order=title', $updated->html);
        static::assertStringNotContainsString('dir=desc', $updated->html);
        static::assertStringContainsString('/catalog/7?utm=mail#interact-pagination-', $updated->html);
        $fresh = $this->runtime->mount('StateOnly', [], null, $this->context);
        static::assertSame(1, Values::map($fresh->state['paging'])['page']);
        static::assertSame(5, Values::map($fresh->state['paging'])['limit']);
        static::assertSame('', Values::map($fresh->state['paging'])['sort']);
    }

    public function testPrivatePageRejectsIntegersOutsideBrowserPrecision(): void
    {
        $mount = $this->runtime->mount('StateOnly', [], null, $this->context);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid pagination page: expected a browser-safe integer.');
        $this->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['paging.page' => PHP_INT_MAX],
        ], $this->context);
    }

    public function testPrivatePagingStillRejectsNullableDeclarations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Definition(NullablePagingIsland::class);
    }

    public function testRejectsAliasesForUndeclaredPagingFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Definition(InvalidAliasIsland::class);
    }

    public function testRepositoryTargetsAndPerRepositorySettingsPreserveSortRestrictions(): void
    {
        $island = new PageIsland();
        $definition = new Definition(PageIsland::class, 'Paging');
        foreach ($definition->paginations() as $pagination) {
            $pagination->initialize($island);
        }

        $identity = new SnapshotData([
            'alias' => 'Paging',
            'id' => 'repository',
            'key' => 'repository',
            'pagePath' => '/catalog/7',
            'state' => [],
            'children' => [],
        ]);
        $island->attachPagination(
            new Pagination($definition, $identity, $this->context, configuration: new Configuration($definition)),
        );
        $result = $island->paginate(TableRegistry::getTableLocator()->get('Resources'), ['Resources' => [
            'limit' => 2,
        ]]);
        static::assertSame(2, $result->perPage());
        static::assertSame(1200, $result->totalCount());
        $mount = $this->runtime->mount(
            'Listing',
            ['settings' => ['Resources' => ['maxLimit' => 7]]],
            null,
            $this->context,
        );
        $updated = $this->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['paging.sort' => 'Resources.id', 'paging.direction' => 'desc', 'paging.limit' => 100],
        ], $this->context);
        static::assertSame('', Values::map($updated->state['paging'])['sort']);
        static::assertSame(7, Values::map($updated->state['paging'])['limit']);
        static::assertStringContainsString('1–7 of 150', $updated->html);
    }

    public function testCallingPaginationOutsideRuntimeHasAnActionableError(): void
    {
        $this->expectException(LogicException::class);
        new PageIsland()->paginate(TableRegistry::getTableLocator()->get('Resources'));
    }

    public function testDirectPaginationNeverSubstitutesTheUpdateEndpointForAMissingPagePath(): void
    {
        $island = new PageIsland();
        $identity = new SnapshotData([
            'alias' => 'Paging',
            'id' => 'legacy',
            'key' => 'legacy',
            'state' => [],
            'children' => [],
        ]);
        $request = $this->context
            ->request
            ->withMethod('POST')
            ->withUri($this->context->request->getUri()->withPath('/_interact/update'));
        $island->attachPagination(
            new Pagination(
                new Definition(PageIsland::class),
                $identity,
                new IslandContext($request, 'default', '/_interact/update'),
            ),
        );
        $repository = $this->createMock(RepositoryInterface::class);
        $repository->expects(static::never())->method('find');
        $this->expectException(InvalidSnapshotException::class);
        $island->paginate($repository);
    }

    public function testSignedPathsAcceptLocalPathsAndRejectNavigationOrigins(): void
    {
        static::assertNull(PagePath::read(null));
        static::assertSame('/subdir/catalog/7', PagePath::read('/subdir/catalog/7'));
        $this->expectException(InvalidArgumentException::class);
        PagePath::read('//other.test/path');
    }
}
