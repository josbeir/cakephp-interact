<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use App\Controller\InvoicesController;
use App\Island\ResourceCount;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\ORM\Locator\LocatorInterface;
use Cake\ORM\TableRegistry;
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
            'resource-count',
            ['search' => 'Resource 01'],
            'count',
            $context,
            new Options('manual', 'Interact/resource_count_placeholder'),
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

        $registry = new Registry(collection: 'isolated-placeholder');
        $registry->register(ResourceCount::class);

        $runtime = new Factory($registry, new SnapshotCodec(str_repeat('d', 32)))->create(
            $view,
            $this->examples->context,
        );
        $pending = $runtime->mount(
            'resource-count',
            [],
            'default-count',
            $this->examples->context,
            new Options('manual', 'Interact/resource_count_placeholder'),
        );
        static::assertStringNotContainsString('host-only', $pending->html);
        static::assertSame('host-only', $view->get('search'));
        $loaded = $runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $this->examples->context);
        static::assertStringContainsString('25 matching resources', $loaded->html);
    }

    public function testArticleValidationPreservesEntityErrorsAndSuccessfulSaveDispatches(): void
    {
        $mount = $this->examples->runtime->mount('article-form', [], 'form', $this->examples->context);
        $invalid = $this->examples->runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => ['title' => ''],
        ], $this->examples->context);
        static::assertSame(['title' => ['Enter a title.']], $invalid->errors);
        static::assertStringContainsString('Enter a title.', $invalid->html);
        static::assertSame([], $invalid->events);
        $valid = $this->examples->runtime->update([
            'snapshot' => $invalid->snapshot,
            'action' => 'save',
            'updates' => ['title' => 'CakePHP examples'],
        ], $this->examples->context);
        static::assertSame([], $valid->errors);
        static::assertTrue($valid->state['saved']);
        static::assertSame('article-saved', $valid->events[0]['name']);
        static::assertSame(1, TableRegistry::getTableLocator()->get('Articles')->find()->count());
    }

    public function testRedirectAndDownloadExamplesUseNativeRoutesAndResponses(): void
    {
        $runtime = $this->examples->runtime;
        $context = $this->examples->context;
        $mount = $runtime->mount('article-redirect', [], 'form', $context);
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
        $invoice = $runtime->mount('invoice-download', [], 'invoice', $context);
        $exported = $runtime->update(['snapshot' => $invoice->snapshot, 'action' => 'download'], $context);
        static::assertSame('/invoices/sample', $exported->download);
        static::assertStringContainsString('Requested 1 downloads.', $exported->html);
        $file = new InvoicesController(new ServerRequest())->download();
        static::assertStringContainsString('invoice.csv', $file->getHeaderLine('Content-Disposition'));
        static::assertSame("Item,Amount\nWorkshop,125.00\n", (string)$file->getBody());
    }

    public function testSearchFinderPaginationEmptyResultsAndLiteralWildcards(): void
    {
        $mount = $this->examples->runtime->mount('resource-search', [], 'search', $this->examples->context);
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
        $mount = $this->examples->runtime->mount('dependent-selects', [], 'choices', $this->examples->context);
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
        $job = $this->examples->runtime->mount('job-progress', [], 'job', $this->examples->context);
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
        $chart = $this->examples->runtime->mount('sales-chart', [], 'chart', $this->examples->context);
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
        $widget = $this->examples->runtime->mount('country-picker', [], 'widget', $this->examples->context);
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

        $mount = $this->examples->runtime->mount('document-upload', [], 'upload', $this->examples->context);
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
