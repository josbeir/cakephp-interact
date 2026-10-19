<?php
declare(strict_types=1);

use Cake\Http\Server;
use TestApp\Application;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && $path === '/interact/js/runtime.js') {
    header('Content-Type: text/javascript');
    readfile(dirname(__DIR__, 3) . '/webroot/js/runtime.js');

    return;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$server = new Server(new Application(dirname(__DIR__) . '/config/'));
$server->emit($server->run());
