<?php
declare(strict_types=1);

use Cake\AttributeResolver\AttributeResolver;
use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Datasource\ConnectionManager;
use Cake\Routing\Router;
use Cake\Utility\Security;

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__, 3));
    $core = dirname((string)new ReflectionClass(BasePlugin::class)->getFileName(), 3) . '/';
    define('CORE_PATH', $core);
    define('CAKE', $core . 'src/');
    define('APP', dirname(__DIR__) . '/');
    define('CONFIG', APP . 'config/');
    define('WWW_ROOT', APP . 'webroot/');
    define('TMP', rtrim(getenv('INTERACT_DEMO_TMP') ?: dirname(__DIR__, 3) . '/build/tmp', '/') . '/');
    define('CACHE', TMP . 'cache/');
    define('LOGS', TMP . 'logs/');
}

foreach ([TMP, CACHE, LOGS] as $directory) {
    if (is_dir($directory)) {
        continue;
    }

    mkdir($directory, 0o755, true);
}

Configure::write('App', [
    'encoding' => 'UTF-8',
    'namespace' => 'TestApp',
    'jsBaseUrl' => 'js/',
    'paths' => ['templates' => [APP . 'templates/']],
]);
Configure::write('debug', true);
Security::setSalt('interact-demo-fixture-only-do-not-use-in-production');
foreach (['_cake_attributes_', '_cake_model_', '_cake_translations_'] as $name) {
    if (Cache::getConfig($name) !== null) {
        continue;
    }

    Cache::setConfig($name, ['className' => ArrayEngine::class]);
}

if (ConnectionManager::getConfig('default') === null) {
    ConnectionManager::setConfig('default', [
        'className' => Connection::class,
        'driver' => Sqlite::class,
        'database' => TMP . 'demo.sqlite',
    ]);
}

$connection = ConnectionManager::get('default');
assert($connection instanceof Connection, 'The configured collaborator must satisfy its declared contract.');
$connection->execute(
    'CREATE TABLE IF NOT EXISTS articles (id INTEGER PRIMARY KEY, title TEXT NOT NULL, owner_id INTEGER NOT NULL)',
);
$connection->execute('CREATE TABLE IF NOT EXISTS outcome_articles (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
$formSchema = (string)file_get_contents(__DIR__ . '/schema/form_articles.sql');
foreach (explode(';', $formSchema) as $statement) {
    if (trim($statement) === '') {
        continue;
    }

    $connection->execute(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $statement));
}

$connection->execute(
    'CREATE TABLE IF NOT EXISTS cities (id INTEGER PRIMARY KEY, country TEXT NOT NULL, name TEXT NOT NULL)',
);
if ($connection->execute('SELECT COUNT(*) FROM articles')->fetchColumn(0) === 0) {
    foreach ([
        'Alpine patterns',
        'CakePHP finders',
        'Composable forms',
        'Dependent choices',
        'Escaping <script>alert(1)</script>',
        'Fresh state',
        'Graceful errors',
    ] as $index => $title) {
        $connection->execute('INSERT INTO articles (id,title,owner_id) VALUES (?,?,?)', [$index + 1, $title, 1]);
    }

    $connection->execute('INSERT INTO articles (id,title,owner_id) VALUES (?,?,?)', [99, 'Private article', 2]);
}

if ($connection->execute('SELECT COUNT(*) FROM cities')->fetchColumn(0) === 0) {
    foreach ([
        [1, 'BE', 'Brussels'],
        [2, 'BE', 'Ghent'],
        [3, 'NL', 'Amsterdam'],
        [4, 'NL', 'Rotterdam'],
    ] as $city) {
        $connection->execute('INSERT INTO cities (id,country,name) VALUES (?,?,?)', $city);
    }
}

require __DIR__ . '/seed_resources.php';

AttributeResolver::setConfig('interactDemoRoutes', [
    'paths' => ['src/Controller/*.php'],
    'basePath' => APP,
    'cache' => false,
]);
// Discover the embedded demo as an application before the enclosing plugin is registered.
AttributeResolver::collection('interactDemoRoutes');

Router::reload();
