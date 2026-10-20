<?php
declare(strict_types=1);

use Cake\Http\Server;
use TestApp\Application;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$demoAssets = [
    '/demo.css' => 'text/css; charset=utf-8',
    '/js/widgets.js' => 'text/javascript; charset=utf-8',
    '/js/widgets.css' => 'text/css; charset=utf-8',
];
if (is_string($path) && isset($demoAssets[$path])) {
    header('Content-Type: ' . $demoAssets[$path]);
    readfile(__DIR__ . $path);

    return;
}

if (is_string($path) && $path === '/interact/js/runtime.js') {
    header('Content-Type: text/javascript');
    readfile(dirname(__DIR__, 3) . '/webroot/js/runtime.js');

    return;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';
if (PHP_SAPI === 'cli-server') {
    // The router handles every URL; nested action paths are not separate PHP scripts.
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
}

$server = new Server(new Application(dirname(__DIR__) . '/config/'));
$server->emit($server->run());
