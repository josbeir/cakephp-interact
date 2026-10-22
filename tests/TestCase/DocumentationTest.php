<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use App\Controller\InvoicesController;
use App\Island\ResourceCountIsland as ResourceCount;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\ORM\Locator\LocatorInterface;
use Cake\ORM\TableRegistry;
use Dom\HTMLDocument;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Runtime\Factory;
use Interact\Runtime\RedirectResult;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\DocumentationRuntime;
use Interact\View\TemplateView;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the copyable documentation source through real signing, rendering, and model behavior.
 */
class DocumentationTest extends TestCase
{
    protected LocatorInterface $previousLocator;

    /**
     * @var mixed Previous application template search paths.
     */
    protected mixed $previousPaths;

    protected DocumentationRuntime $examples;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousLocator = TableRegistry::getTableLocator();
        $this->previousPaths = Configure::read('App.paths.templates');
        $this->examples = new DocumentationRuntime();
    }

    protected function tearDown(): void
    {
        TableRegistry::setTableLocator($this->previousLocator);
        Configure::write('App.paths.templates', $this->previousPaths);
        parent::tearDown();
    }

    public function testDeferredCountUsesDeclaredDefaultPropsAndNativePlaceholderElements(): void
    {
        $runtime = $this->examples->runtime;
        $context = $this->examples->context;
        $pending = $runtime->mount(
            'ResourceCount',
            ['search' => 'Resource 01'],
            'count',
            $context,
            new Options('manual', 'Loading/resource_count'),
        );
        static::assertSame([], $pending->state);
        static::assertStringContainsString('Resource 01', $pending->html);
        static::assertStringNotContainsString('matching resources', $pending->html);
        $loaded = $runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $context);
        static::assertStringContainsString('1 matching resources', $loaded->html);
        $refreshed = $runtime->update(['snapshot' => $loaded->snapshot], $context);
        static::assertStringContainsString('1 matching resources', $refreshed->html);
    }

    public function testPlaceholderReceivesDefaultsWithoutControllerVariableLeakage(): void
    {
        $view = new TemplateView($this->examples->context->request);
        $view->set('search', 'host-only');

        $registry = new Registry();
        $registry->register(ResourceCount::class);

        $runtime = new Factory($registry, new SnapshotCodec(str_repeat('d', 32)))->create(
            $view,
            $this->examples->context,
        );
        $pending = $runtime->mount(
            'ResourceCount',
            [],
            'default-count',
            $this->examples->context,
            new Options('manual', 'Loading/resource_count'),
        );
        static::assertStringNotContainsString('host-only', $pending->html);
        static::assertSame('host-only', $view->get('search'));
        $loaded = $runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $this->examples->context);
        static::assertStringContainsString('25 matching resources', $loaded->html);
    }

    public function testArticleValidationPreservesEntityErrorsAndSuccessfulSaveDispatches(): void
    {
        $mount = $this->examples->runtime->mount('Article/Form', [], 'form', $this->examples->context);
        $invalid = $this->examples->runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => [
                'data' => [
                    'title' => '',
                    'body' => 'Draft body',
                    'published' => false,
                    'topics' => [],
                    'comments' => [],
                ],
            ],
        ], $this->examples->context);
        static::assertSame(['title' => ['Enter a title.']], $invalid->errors);
        static::assertStringContainsString('Enter a title.', $invalid->html);
        static::assertSame([], $invalid->events);
        $valid = $this->examples->runtime->update([
            'snapshot' => $invalid->snapshot,
            'action' => 'save',
            'updates' => ['data.title' => 'CakePHP examples'],
        ], $this->examples->context);
        static::assertSame([], $valid->errors);
        static::assertTrue($valid->state['saved']);
        static::assertSame('article-saved', $valid->events[0]['name']);
        static::assertSame(1, TableRegistry::getTableLocator()->get('FormArticles')->find()->count());
    }

    public function testNestedArticleDraftMarshallingValidationAndExplicitSaves(): void
    {
        $runtime = $this->examples->runtime;
        $context = $this->examples->context;
        $mount = $runtime->mount('Article/Form', [], 'form', $context);
        $draft = [
            'title' => 'Nested article',
            'body' => '<script>draft</script>',
            'published' => true,
            'topics' => ['cakephp', 'php'],
            'comments' => [['body' => '', 'approved' => false]],
        ];
        $preview = $runtime->update(['snapshot' => $mount->snapshot, 'updates' => ['data' => $draft]], $context);
        static::assertSame($draft, $preview->state['data']);
        static::assertSame(
            0,
            $this->examples->connection->execute('SELECT COUNT(*) FROM form_articles')->fetchColumn(0),
        );
        $invalid = $runtime->update(['snapshot' => $preview->snapshot, 'action' => 'save'], $context);
        static::assertSame(['comments.0.body' => ['Enter a comment.']], $invalid->errors);
        static::assertSame($draft, $invalid->state['data']);
        static::assertStringContainsString('Enter a comment.', $invalid->html);
        static::assertStringContainsString('&lt;script&gt;draft&lt;/script&gt;', $invalid->html);
        static::assertSame(0, $this->examples->connection->execute('SELECT COUNT(*) FROM comments')->fetchColumn(0));
        $added = $runtime->update([
            'snapshot' => $invalid->snapshot,
            'action' => 'addComment',
            'updates' => ['data.comments.0.body' => 'First comment'],
        ], $context);
        static::assertIsArray($added->state['data']);
        static::assertIsArray($added->state['data']['comments']);
        static::assertCount(2, $added->state['data']['comments']);
        $removed = $runtime->update([
            'snapshot' => $added->snapshot,
            'action' => 'removeComment',
            'args' => [1],
        ], $context);
        static::assertIsArray($removed->state['data']);
        static::assertIsArray($removed->state['data']['comments']);
        static::assertCount(1, $removed->state['data']['comments']);
        $saved = $runtime->update([
            'snapshot' => $removed->snapshot,
            'action' => 'save',
            'updates' => ['data.comments.0.approved' => true],
        ], $context);
        static::assertSame([], $saved->errors);
        static::assertTrue($saved->state['saved']);
        $articles = TableRegistry::getTableLocator()->get('FormArticles');
        $article = $articles->get($saved->events[0]['detail']['id']);
        static::assertSame('<script>draft</script>', $article->get('body'));
        static::assertTrue($article->get('published'));
        static::assertSame(['cakephp', 'php'], $article->get('topics'));
        $comment = TableRegistry::getTableLocator()->get('Comments')->find()->firstOrFail();
        static::assertSame('First comment', $comment->get('body'));
        static::assertTrue($comment->get('approved'));
        static::assertSame($article->get('id'), $comment->get('article_id'));
        $preview = $runtime->update([
            'snapshot' => $saved->snapshot,
            'updates' => ['data.title' => 'Unsaved title'],
        ], $context);
        static::assertFalse($preview->state['saved']);
        static::assertSame('Nested article', $articles->get($article->get('id'))->get('title'));
    }

    public function testFormHelperReadsDraftAndFailedEntityValuesWithoutFieldOverrides(): void
    {
        $runtime = $this->examples->runtime;
        $context = $this->examples->context;
        $mount = $runtime->mount('Article/Form', [], 'form', $context);
        $draft = [
            'title' => 'Current title',
            'body' => 'Body <with> markup',
            'published' => true,
            'topics' => ['php'],
            'comments' => [['body' => 'Current comment', 'approved' => true]],
        ];
        $preview = $runtime->update(['snapshot' => $mount->snapshot, 'updates' => ['data' => $draft]], $context);
        $html = HTMLDocument::createFromString('<!doctype html><html><body>' . $preview->html . '</body></html>');
        static::assertSame('Current title', $html->querySelector('input[name="title"]')?->getAttribute('value'));
        static::assertSame('Body <with> markup', $html->querySelector('textarea[name="body"]')?->textContent);
        static::assertTrue($html->querySelector('input[type="checkbox"][name="published"]')?->hasAttribute('checked'));
        static::assertTrue($html->querySelector('input[name="topics[]"][value="php"]')?->hasAttribute('checked'));
        static::assertFalse($html->querySelector('input[name="topics[]"][value="cakephp"]')?->hasAttribute('checked'));
        static::assertSame(
            'Current comment',
            $html->querySelector('input[name="comments[0][body]"]')?->getAttribute('value'),
        );
        static::assertTrue(
            $html->querySelector('input[type="checkbox"][name="comments[0][approved]"]')?->hasAttribute('checked'),
        );
        $invalidTitle = str_repeat('x', 121);
        $failed = $runtime->update([
            'snapshot' => $preview->snapshot,
            'action' => 'save',
            'updates' => ['data.title' => $invalidTitle],
        ], $context);
        static::assertSame(['title' => ['Use up to 120 characters.']], $failed->errors);
        $html = HTMLDocument::createFromString('<!doctype html><html><body>' . $failed->html . '</body></html>');
        static::assertSame($invalidTitle, $html->querySelector('input[name="title"]')?->getAttribute('value'));
        static::assertSame('Body <with> markup', $html->querySelector('textarea[name="body"]')?->textContent);
        static::assertSame(
            'Current comment',
            $html->querySelector('input[name="comments[0][body]"]')?->getAttribute('value'),
        );
    }

    public function testCreateFormRejectsAssociationIdsBeforeMarshallingAndIgnoresUnlistedFields(): void
    {
        $runtime = $this->examples->runtime;
        $context = $this->examples->context;
        $mount = $runtime->mount('Article/Form', [], 'form', $context);
        $forged = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => ['data.comments.0.id' => 999],
        ], $context);
        static::assertSame(['_form' => ['Existing comment IDs are not accepted.']], $forged->errors);
        $forged = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => ['data.id' => null],
        ], $context);
        static::assertSame(['_form' => ['Existing article IDs are not accepted.']], $forged->errors);
        static::assertSame(
            0,
            $this->examples->connection->execute('SELECT COUNT(*) FROM form_articles')->fetchColumn(0),
        );
        $valid = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => ['data.title' => 'Allowed fields', 'data.comments' => [], 'data.owner_id' => 999],
        ], $context);
        static::assertSame([], $valid->errors);
        static::assertSame(
            1,
            $this->examples->connection->execute('SELECT COUNT(*) FROM form_articles')->fetchColumn(0),
        );
    }

    public function testRedirectAndDownloadExamplesUseNativeRoutesAndResponses(): void
    {
        $runtime = $this->examples->runtime;
        $context = $this->examples->context;
        $mount = $runtime->mount('ArticleRedirect', [], 'form', $context);
        $invalid = $runtime->update(['snapshot' => $mount->snapshot, 'action' => 'save'], $context);
        static::assertNotEmpty($invalid->errors);
        static::assertStringContainsString('Enter a title.', $invalid->html);
        $saved = $runtime->update([
            'snapshot' => $invalid->snapshot,
            'action' => 'save',
            'updates' => ['title' => 'Redirect example'],
        ], $context);
        static::assertInstanceOf(RedirectResult::class, $saved);
        static::assertSame('/articles/1', $saved->destination);
        $invoice = $runtime->mount('InvoiceDownload', [], 'invoice', $context);
        $exported = $runtime->update(['snapshot' => $invoice->snapshot, 'action' => 'download'], $context);
        static::assertSame('/invoices/sample', $exported->download);
        static::assertStringContainsString('Requested 1 downloads.', $exported->html);
        $file = new InvoicesController(new ServerRequest())->download();
        static::assertStringContainsString('invoice.csv', $file->getHeaderLine('Content-Disposition'));
        static::assertSame("Item,Amount\nWorkshop,125.00\n", (string)$file->getBody());
    }

    public function testSearchFinderPaginationEmptyResultsAndLiteralWildcards(): void
    {
        $mount = $this->examples->runtime->mount('ResourceSearch', [], 'search', $this->examples->context);
        static::assertStringContainsString('25 results.', $mount->html);
        $last = $this->examples->runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'goTo',
            'args' => [999],
        ], $this->examples->context);
        static::assertSame(3, $last->state['page']);
        $search = $this->examples->runtime->update([
            'snapshot' => $last->snapshot,
            'updates' => ['search' => 'Resource 01'],
        ], $this->examples->context);
        static::assertSame(1, $search->state['page']);
        static::assertStringContainsString('1 results.', $search->html);
        $empty = $this->examples->runtime->update([
            'snapshot' => $search->snapshot,
            'updates' => ['search' => '%'],
        ], $this->examples->context);
        static::assertStringContainsString('0 results.', $empty->html);
    }

    public function testDependentChoicesResetCityAndRejectForgedMembership(): void
    {
        $mount = $this->examples->runtime->mount('DependentSelects', [], 'choices', $this->examples->context);
        static::assertStringContainsString('Brussels', $mount->html);
        $changed = $this->examples->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['country' => 'NL'],
        ], $this->examples->context);
        static::assertNull($changed->state['cityId']);
        static::assertStringContainsString('Amsterdam', $changed->html);
        static::assertStringNotContainsString('Brussels', $changed->html);
        $forged = $this->examples->runtime->update([
            'snapshot' => $changed->snapshot,
            'updates' => ['cityId' => 1],
            'action' => 'confirm',
        ], $this->examples->context);
        static::assertArrayHasKey('cityId', $forged->errors);
        $valid = $this->examples->runtime->update([
            'snapshot' => $forged->snapshot,
            'updates' => ['cityId' => 3],
            'action' => 'confirm',
        ], $this->examples->context);
        static::assertTrue($valid->state['confirmed']);
    }

    public function testPollingCompletionRestartAndChartData(): void
    {
        $job = $this->examples->runtime->mount('JobProgress', [], 'job', $this->examples->context);
        for ($step = 0; $step < 4; $step++) {
            $job = $this->examples->runtime->update([
                'snapshot' => $job->snapshot,
                'action' => 'refreshStatus',
            ], $this->examples->context);
        }

        static::assertSame(100, $job->state['progress']);
        static::assertFalse($job->state['running']);
        $job = $this->examples->runtime->update([
            'snapshot' => $job->snapshot,
            'action' => 'restart',
        ], $this->examples->context);
        static::assertSame(0, $job->state['progress']);
        static::assertTrue($job->state['running']);
        $chart = $this->examples->runtime->mount('SalesChart', [], 'chart', $this->examples->context);
        $chart = $this->examples->runtime->update([
            'snapshot' => $chart->snapshot,
            'action' => 'refreshChart',
        ], $this->examples->context);
        static::assertSame(
            ['labels' => ['Monday', 'Tuesday'], 'datasets' => [['label' => 'Orders', 'data' => [13, 19]]]],
            $chart->state['chartData'],
        );
        static::assertArrayNotHasKey('chartData', $chart->writable);
    }

    public function testCountryWidgetValidationAndReset(): void
    {
        $widget = $this->examples->runtime->mount('CountryPicker', [], 'widget', $this->examples->context);
        $invalid = $this->examples->runtime->update([
            'snapshot' => $widget->snapshot,
            'updates' => ['country' => 'forged'],
            'action' => 'confirm',
        ], $this->examples->context);
        static::assertArrayHasKey('country', $invalid->errors);
        $valid = $this->examples->runtime->update([
            'snapshot' => $invalid->snapshot,
            'updates' => ['country' => 'BE'],
            'action' => 'confirm',
        ], $this->examples->context);
        static::assertTrue($valid->state['confirmed']);
        $reset = $this->examples->runtime->update([
            'snapshot' => $valid->snapshot,
            'action' => 'resetSelection',
        ], $this->examples->context);
        static::assertSame('', $reset->state['country']);
        static::assertFalse($reset->state['confirmed']);
    }

    public function testSingleUploadRetainsValidationAndStoresAnAcceptedFile(): void
    {
        $this->examples->connection->execute('INSERT INTO documents (id, filename) VALUES (?, ?)', [
            random_int(100_000, 999_999),
            'reserved',
        ]);
        $directory = TMP . 'example-documents/';
        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        $mount = $this->examples->runtime->mount('DocumentUpload', [], 'upload', $this->examples->context);
        $invalid = $this->examples->runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
        ], $this->examples->context);
        static::assertArrayHasKey('document', $invalid->errors);
        $stream = new Stream('php://temp', 'w+');
        $stream->write('documentation file');

        $file = new UploadedFile($stream, 18, UPLOAD_ERR_OK, 'document.txt', 'text/plain');
        $context = new IslandContext(
            $this->examples->context->request->withUploadedFiles(['file' => $file]),
            'docs',
            '/docs-update',
        );
        $staged = $this->examples->runtime->update([
            'snapshot' => $invalid->snapshot,
            'upload' => ['kind' => 'stage', 'field' => 'document'],
        ], $context);
        static::assertStringContainsString('document.txt', $staged->html);
        $saved = $this->examples->runtime->update([
            'snapshot' => $staged->snapshot,
            'action' => 'save',
        ], $this->examples->context);
        static::assertSame([], $saved->errors);
        static::assertStringContainsString('Document saved.', $saved->html);
        $record = TableRegistry::getTableLocator()
            ->get('Documents')
            ->find()
            ->where(['filename' => 'document.txt'])
            ->firstOrFail();
        $path = $directory . (string)$record->get('id') . '.upload';
        try {
            static::assertSame('documentation file', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
