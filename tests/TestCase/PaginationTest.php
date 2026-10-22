<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\Paging\NumericPaginator;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Pagination\Configuration;
use Interact\Pagination\PagePath;
use Interact\Pagination\Pagination;
use Interact\Runtime\Factory;
use Interact\Runtime\Runtime;
use Interact\Runtime\SnapshotData;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CounterIsland;
use Interact\Test\Fixture\Pagination\ListingIsland;
use Interact\Test\Fixture\Pagination\PageIsland;
use Interact\Test\Fixture\Pagination\PrivatePageIsland;
use Interact\Test\Fixture\Pagination\SortOnlyIsland;
use Interact\View\TemplateView;
use InvalidArgumentException;
use LogicException;

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

    public function testRejectsPageStateWithoutUrlOwnership(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration(new Definition(PrivatePageIsland::class))->fields();
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

    public function testSignedPathsAcceptLocalPathsAndRejectNavigationOrigins(): void
    {
        static::assertNull(PagePath::read(null));
        static::assertSame('/subdir/catalog/7', PagePath::read('/subdir/catalog/7'));
        $this->expectException(InvalidArgumentException::class);
        PagePath::read('//other.test/path');
    }
}
