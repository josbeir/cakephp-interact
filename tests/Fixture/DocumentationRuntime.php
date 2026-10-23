<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use App\Model\Table\ArticlesTable;
use App\Model\Table\CitiesTable;
use App\Model\Table\DocumentsTable;
use App\Model\Table\ResourcesTable;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use Cake\Utility\Fs\Finder;
use Cake\Utility\Fs\Path;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Upload\LocalUploadStorage;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadManager;
use Interact\View\TemplateView;
use SplFileInfo;
use TestApp\Island\Article\FormIsland as ArticleForm;
use TestApp\Model\Table\CommentsTable;
use TestApp\Model\Table\FormArticlesTable;

/**
 * Runs the exact application examples with native views and an isolated SQLite database.
 *
 * The article form consumes the demo-owned island, models, schema, and template.
 * Other examples use focused documentation fixtures; all share an isolated database.
 */
class DocumentationRuntime
{
    public Connection $connection;

    public Runtime $runtime;

    public IslandContext $context;

    /**
     * Configure public examples, deterministic rows, signing, and PHP template rendering.
     */
    public function __construct(?UploadManager $uploads = null)
    {
        $this->connection = new Connection(['driver' => Sqlite::class, 'database' => ':memory:']);
        $sources = array_map(
            static fn(SplFileInfo $source): string => $source->getPathname(),
            iterator_to_array(
                new Finder()->in(Path::join(dirname(__DIR__), 'docs/app/schema'))
                    ->recursive(false)
                    ->name('*.sql')
                    ->all(),
                false,
            ),
        );
        sort($sources, SORT_STRING);
        $sources[] = Path::join(dirname(__DIR__), 'test_app/config/schema/form_articles.sql');
        foreach ($sources as $source) {
            $sql = (string)file_get_contents($source);
            foreach (explode(';', $sql) as $statement) {
                if (trim($statement) === '') {
                    continue;
                }

                $this->connection->execute($statement);
            }
        }

        $locator = new TableLocator();
        $locator->set('FormArticles', new FormArticlesTable([
            'alias' => 'FormArticles',
            'connection' => $this->connection,
        ]));
        $locator->set('Comments', new CommentsTable(['alias' => 'Comments', 'connection' => $this->connection]));
        $locator->set('Articles', new ArticlesTable(['alias' => 'Articles', 'connection' => $this->connection]));
        $locator->set('Cities', new CitiesTable(['alias' => 'Cities', 'connection' => $this->connection]));
        $locator->set('Documents', new DocumentsTable(['alias' => 'Documents', 'connection' => $this->connection]));
        $locator->set('Resources', new ResourcesTable(['alias' => 'Resources', 'connection' => $this->connection]));
        TableRegistry::setTableLocator($locator);
        Configure::write('App.paths.templates', [
            dirname(__DIR__) . '/docs/app/templates/',
            dirname(__DIR__) . '/test_app/templates/',
        ]);
        Configure::write('App.namespace', 'App');
        $registry = new Registry();
        // This reused demo class belongs to TestApp; the other examples resolve from App on demand.
        $registry->register(ArticleForm::class);

        if (!isset(Router::getRouteCollection()->named()['articles:view'])) {
            Router::createRouteBuilder('/')->connect(
                '/articles/{id}',
                ['controller' => 'Articles', 'action' => 'view'],
                ['_name' => 'articles:view', 'pass' => ['id']],
            );
            Router::createRouteBuilder('/')->connect(
                '/invoices/sample',
                ['controller' => 'Invoices', 'action' => 'download'],
                ['_name' => 'invoices:download'],
            );
        }

        $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
        $request = new ServerRequest(['session' => $session, 'url' => '/documentation-example']);
        $this->context = new IslandContext($request->withAttribute('csrfToken', 'docs-csrf'), 'docs', '/docs-update');
        $config = new UploadConfig(['directory' => TMP . 'documentation-uploads']);
        $uploads ??= new UploadManager(new LocalUploadStorage($config), $config);
        $factory = new Factory($registry, new SnapshotCodec(str_repeat('d', 32)), $uploads);
        $this->runtime = $factory->create(new TemplateView($request), $this->context);
    }
}
