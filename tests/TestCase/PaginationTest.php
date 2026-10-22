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
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CounterIsland;
use Interact\Test\Fixture\Pagination\ListingIsland;
use Interact\Test\Fixture\Pagination\NullablePageIsland;
use Interact\Test\Fixture\Pagination\PageIsland;
use Interact\Test\Fixture\Pagination\PrivatePageIsland;
use Interact\Test\Fixture\Pagination\SortOnlyIsland;
use Interact\Test\Fixture\Pagination\StateOnlyIsland;
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
            ['page' => 1, 'search' => '', 'settings' => ['limit' => 3], 'twice' => false, 'allowed' => true],
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
            ['snapshot' => $initial->snapshot, 'updates' => ['page' => 999], 'pageQuery' => '?utm=mail'],
            new IslandContext($request, 'default', '/_interact/update'),
        );
        static::assertSame(30, $updated->state['page']);
        static::assertStringContainsString('Accepted page 30.', $updated->html);
        static::assertStringContainsString('146–150 of 150', $updated->html);
        static::assertStringContainsString('/catalog/7?utm=mail&amp;p=29', $updated->html);
        $signed = $this->codec->open($updated->snapshot, $this->context->binding(), 'default');
        static::assertIsArray($signed['state']);
        static::assertSame(30, $signed['state']['page']);
        $empty = $this->runtime->update([
            'snapshot' => $updated->snapshot,
            'updates' => ['search' => 'no such resource'],
        ], $this->context);
        static::assertSame(1, $empty->state['page']);
        static::assertStringContainsString('0–0 of 0', $empty->html);
        $negative = $this->runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => ['page' => -5],
        ], $this->context);
        static::assertSame(1, $negative->state['page']);
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
            ['snapshot' => $fresh->snapshot, 'updates' => ['page' => 2]],
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
        yield 'synchronization' => [['updates' => ['page' => 2]]];
        yield 'renderless action' => [['action' => 'changePage', 'args' => [2]]];
        yield 'history restoration' => [['urlRestore' => true, 'updates' => ['page' => 2, 'search' => '']]];
        yield 'deferred initialization' => [['initialize' => true]];
    }

    public function testExplicitLimitAndSortStateOverrideConfigurationAndStayAllowlisted(): void
    {
        $initial = $this->runtime->mount('Listing', ['settings' => ['limit' => 2]], null, $this->context);
        static::assertStringContainsString('1–5 of 150', $initial->html);
        $sorted = $this->runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => ['sort' => 'title', 'direction' => 'DESC', 'limit' => 500],
        ], $this->context);
        static::assertSame(10, $sorted->state['limit']);
        static::assertSame('desc', $sorted->state['direction']);
        static::assertSame('title', $sorted->state['sort']);
        static::assertStringContainsString('1–10 of 150', $sorted->html);
        static::assertStringContainsString('order=title', $sorted->html);
        $invalid = $this->runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => ['sort' => 'Resources.id', 'direction' => 'sql'],
        ], $this->context);
        static::assertSame('', $invalid->state['sort']);
        static::assertSame('asc', $invalid->state['direction']);
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
        static::assertSame(2, $mounted->state['page']);
        static::assertStringContainsString('/catalog/7?utm=mail&amp;right_p=4&amp;left_p=3', $mounted->html);
        $restored = $this->runtime->update([
            'snapshot' => $mounted->snapshot,
            'urlRestore' => true,
            'updates' => ['page' => 3, 'search' => '', 'sort' => '', 'direction' => 'asc', 'limit' => 5],
        ], $this->context);
        static::assertSame(3, $restored->state['page']);
        static::assertStringContainsString('11–15 of 150', $restored->html);
        $renderless = $this->runtime->update([
            'snapshot' => $restored->snapshot,
            'action' => 'changePage',
            'args' => [999],
        ], $this->context);
        static::assertNull($renderless->payload()['html']);
        static::assertSame(999, $renderless->state['page']);
        static::assertSame(
            30,
            $this->runtime->update(['snapshot' => $renderless->snapshot], $this->context)->state['page'],
        );
    }

    public function testDeletingTheLastPageClampsTheNextRefreshWithoutAnApplicationAction(): void
    {
        $mount = $this->runtime->mount('Paging', [], null, $this->context);
        $last = $this->runtime->update(['snapshot' => $mount->snapshot, 'updates' => ['page' => 30]], $this->context);
        $connection = ConnectionManager::get('default');
        static::assertInstanceOf(Connection::class, $connection);
        $connection->begin();
        try {
            $connection->execute(
                "DELETE FROM resources WHERE id IN (SELECT id FROM resources WHERE category = 'cakephp' ORDER BY id DESC LIMIT 5)",
            );
            $refresh = $this->runtime->update(['snapshot' => $last->snapshot], $this->context);
            static::assertSame(29, $refresh->state['page']);
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
        new Configuration(new Definition(CounterIsland::class))->fields();
    }

    public function testPrivatePagingIsWritableWithoutOwningAnyPagingQueryParameter(): void
    {
        $fields = new Configuration(new Definition(PrivatePageIsland::class))->fields();
        static::assertNull($fields['page']->url);
        $mount = $this->runtime->mount('PrivatePaging', [], null, $this->context);
        static::assertArrayNotHasKey('page', $mount->url);
        $updated = $this->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['page' => 2, 'search' => 'CakePHP'],
            'pageQuery' => '?utm=mail',
        ], $this->context);
        static::assertSame(2, $updated->state['page']);
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
            'updates' => ['page' => 3, 'limit' => 7, 'sort' => 'title', 'direction' => 'desc'],
            'pageQuery' => '?utm=mail',
        ], $this->context);
        static::assertSame([], $updated->url);
        static::assertSame(3, $updated->state['page']);
        static::assertSame(7, $updated->state['limit']);
        static::assertSame('desc', $updated->state['direction']);
        static::assertStringContainsString('15–21 of 150', $updated->html);
        static::assertStringNotContainsString('order=title', $updated->html);
        static::assertStringNotContainsString('dir=desc', $updated->html);
        static::assertStringContainsString('/catalog/7?utm=mail#interact-pagination-', $updated->html);
        $fresh = $this->runtime->mount('StateOnly', [], null, $this->context);
        static::assertSame(1, $fresh->state['page']);
        static::assertSame(5, $fresh->state['limit']);
        static::assertSame('', $fresh->state['sort']);
    }

    public function testPrivatePageRejectsIntegersOutsideBrowserPrecision(): void
    {
        $mount = $this->runtime->mount('StateOnly', [], null, $this->context);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pagination page must be a browser-safe integer.');
        $this->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['page' => PHP_INT_MAX],
        ], $this->context);
    }

    public function testPrivatePagingStillRejectsNullableDeclarations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration(new Definition(NullablePageIsland::class))->fields();
    }

    public function testRejectsOrphanedSortDeclarations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration(new Definition(SortOnlyIsland::class))->fields();
    }

    public function testRepositoryTargetsAndPerRepositorySettingsPreserveSortRestrictions(): void
    {
        $island = new PageIsland();
        $definition = new Definition(PageIsland::class, 'Paging');
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
            'updates' => ['sort' => 'Resources.id', 'direction' => 'desc', 'limit' => 100],
        ], $this->context);
        static::assertSame('', $updated->state['sort']);
        static::assertSame(7, $updated->state['limit']);
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
