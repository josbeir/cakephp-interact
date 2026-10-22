<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Core\Configure;
use Cake\Datasource\Paging\PaginatedResultSet;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\View\Helper\PaginatorHelper as NativePaginatorHelper;
use Interact\Discovery\Declaration;
use Interact\Pagination\Links;
use Interact\Pagination\Paginated;
use Interact\View\Helper\PaginatorHelper;
use Interact\View\TemplateView;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Verifies native paginator markup remains customizable while result and URL scopes are isolated.
 * Link targets are generated independently of template strings and never depend on the update route.
 */
class PaginatorHelperTest extends TestCase
{
    protected TemplateView $view;

    protected PaginatorHelper $helper;

    /**
     * Load the same helper alias used by island templates without a controller pagination component.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.paths.templates', [dirname(__DIR__) . '/Fixture/views/templates/']);
        $this->view = new TemplateView(new ServerRequest(['url' => '/_interact/update']));
        $helper = $this->view->loadHelper('Paginator');
        static::assertInstanceOf(PaginatorHelper::class, $helper);
        $this->helper = $helper;
    }

    /**
     * Supply native paging metadata with State defaults deliberately different from paginator defaults.
     *
     * @param list<string> $names Explicit paging capabilities.
     */
    protected function paginatedFixture(
        string $path = '/catalog/7',
        array $names = ['page', 'sort', 'direction'],
    ): Paginated {
        $native = new PaginatedResultSet([], [
            'count' => 0,
            'totalCount' => 30,
            'currentPage' => 2,
            'requestedPage' => 2,
            'perPage' => 10,
            'pageCount' => 3,
            'hasPrevPage' => true,
            'hasNextPage' => true,
            'alias' => 'Resources',
            'scope' => null,
            'sort' => 'title',
            'direction' => 'desc',
            'sortDefault' => 'title',
            'directionDefault' => 'desc',
            'limit' => null,
        ]);
        $fields = [
            'page' => ['name' => 'p', 'type' => 'int', 'default' => 3, 'history' => true],
            'sort' => ['name' => 'order', 'type' => 'string', 'default' => '', 'history' => true],
            'direction' => ['name' => 'dir', 'type' => 'string', 'default' => 'asc', 'history' => true],
        ];
        $links = new Links($path, '?external=a.b&external=keep', $fields, [
            'page' => 2,
            'sort' => 'title',
            'direction' => 'desc',
        ]);

        return new Paginated($native, $links, $names);
    }

    public function testNativeTemplateCustomizationAndCompleteStateDefaults(): void
    {
        $result = $this->paginatedFixture();
        $restore = $this->helper->islandScope($result);
        $this->helper->setTemplates([
            'number' => '<li><a class="custom" href="{{url}}"><strong>{{text}}</strong></a></li>',
        ]);
        $html = $this->helper->numbers();
        static::assertStringContainsString('class="custom"', $html);
        static::assertStringContainsString('p=1&amp;order=title&amp;dir=desc', $html);
        static::assertStringContainsString('external=a.b&amp;external=keep', $html);
        static::assertStringNotContainsString('data-interact', $html);
        static::assertStringContainsString('data-interact-pagination', $result->links->markup());
        static::assertStringContainsString('&quot;page&quot;:1', $result->links->markup());
        static::assertStringContainsString('http://localhost/catalog/7?', $this->helper->generateUrl(
            ['page' => 1],
            [],
            ['fullBase' => true],
        ));
        static::assertStringContainsString('&order=title&dir=desc', $this->helper->generateUrl(
            ['page' => 1],
            [],
            ['escape' => false],
        ));
        $restore();
    }

    public function testCombinedSortLinksBecomeSeparateExplicitStateEdits(): void
    {
        $result = $this->paginatedFixture();
        $restore = $this->helper->islandScope($result);
        $this->helper->options(['sortFormat' => 'combined']);
        $html = $this->helper->sort('title', 'Title');
        static::assertStringContainsString('p=1&amp;order=title', $html);
        static::assertStringNotContainsString('order=title-asc', $html);
        static::assertStringContainsString('&quot;direction&quot;:&quot;asc&quot;', $result->links->markup());
        $restore();
    }

    public function testNestedScopesRestoreOptionsTemplatesAndParentDataset(): void
    {
        $this->helper->setTemplates(['nextActive' => '<a class="outer" href="{{url}}">{{text}}</a>']);
        $outer = $this->helper->islandScope($this->paginatedFixture('/outer'));
        $this->helper->options(['sortFormat' => 'combined']);
        $inner = $this->helper->islandScope($this->paginatedFixture('/inner'));
        $this->helper->setTemplates(['nextActive' => '<a class="inner" href="{{url}}">{{text}}</a>']);
        $this->helper->options(['sortFormat' => 'separate']);
        static::assertStringContainsString('/inner?', $this->helper->next('Next'));
        $inner();
        static::assertSame('combined', $this->helper->getConfig('options.sortFormat'));
        static::assertStringContainsString('class="outer"', $this->helper->next('Next'));
        static::assertStringContainsString('/outer?', $this->helper->next('Next'));
        $outer();
        static::assertSame('separate', $this->helper->getConfig('options.sortFormat'));
    }

    public function testFailedTemplateRenderingRestoresHelperAndViewScopes(): void
    {
        $restore = $this->helper->islandScope($this->paginatedFixture('/outer'));
        $this->view->set('outside', 'retained');
        try {
            $this->view->island(new Declaration('Throwing', 'island/throwing'), [
                'rows' => $this->paginatedFixture('/inner'),
            ]);
            static::fail('Expected template failure.');
        } catch (RuntimeException $runtimeException) {
            static::assertSame('Template failure.', $runtimeException->getMessage());
        }

        static::assertSame('retained', $this->view->get('outside'));
        static::assertStringContainsString('/outer?', $this->helper->next('Next'));
        $restore();
    }

    public function testSortControlsRequireExplicitSortCapabilities(): void
    {
        $this->helper->islandScope($this->paginatedFixture(names: ['page']));
        $this->expectException(InvalidArgumentException::class);
        $this->helper->sort('title');
    }

    public function testNativeResultsAndExplicitDestinationOverridesRemainNative(): void
    {
        Router::createRouteBuilder('/')->connect('/alternate', ['controller' => 'Resources', 'action' => 'index']);
        $native = new PaginatedResultSet([], ['currentPage' => 1]);
        $this->helper->setPaginated($native);
        static::assertStringContainsString('/alternate', $this->helper->generateUrl(['page' => 2], [
            'controller' => 'Resources',
            'action' => 'index',
        ]));
        $result = $this->paginatedFixture();
        $this->helper->islandScope($result);
        static::assertStringContainsString('/alternate', $this->helper->generateUrl(['page' => 2], [
            'controller' => 'Resources',
            'action' => 'index',
        ]));
        static::assertSame('', $result->links->markup());
    }

    public function testRejectsHelpersThatCannotIsolatePaginationWithoutChangingViewVariables(): void
    {
        $this->view->helpers()->unload('Paginator');
        $this->view->loadHelper('Paginator', ['className' => NativePaginatorHelper::class]);
        $this->view->set('outside', 'retained');
        try {
            $this->view->island(new Declaration('Throwing', 'island/throwing'), []);
            static::fail('Expected incompatible helper error.');
        } catch (LogicException $logicException) {
            static::assertStringContainsString('must extend', $logicException->getMessage());
        }

        static::assertSame('retained', $this->view->get('outside'));
    }

    public function testUrlEncodingPreservesEmptyListsFalseAndUnrelatedRepeatedParameters(): void
    {
        $fields = [
            'page' => ['name' => 'p', 'type' => 'int', 'default' => 1, 'history' => true],
            'tags' => ['name' => 'tags', 'type' => 'array', 'default' => ['default'], 'history' => false],
            'enabled' => ['name' => 'enabled', 'type' => 'bool', 'default' => true, 'history' => false],
        ];
        $links = new Links('/catalog', '?external=a&external=b&tags%5B0%5D=old&p=9', $fields, [
            'page' => 1,
            'tags' => [],
            'enabled' => false,
        ]);
        static::assertSame('/catalog?external=a&external=b&p=2&tags=&enabled=0', $links->generate(['page' => 2], [
            'page',
        ]));
    }
}
