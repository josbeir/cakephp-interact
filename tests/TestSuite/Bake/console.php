<?php
declare(strict_types=1);

use Bake\BakePlugin;
use Cake\Console\CommandRunner;
use Cake\Core\BasePlugin;
use Composer\Autoload\ClassLoader;
use TestApp\Application;

/** @var ClassLoader $loader */
$loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$storage = $argv[1] ?? throw new InvalidArgumentException('Supply a disposable plugin directory.');
if (($argv[2] ?? '') === 'without-bake') {
    $loader->setPsr4('Bake\\', []);
    $loader->setPsr4('Cake\\TwigView\\', []);
    if (class_exists(BakePlugin::class)) {
        throw new RuntimeException('The production scenario must not be able to autoload Bake.');
    }
}

$app = new Application(dirname(__DIR__, 2) . '/test_app/config/');
$app->addPlugin(new BasePlugin(['name' => 'BakedShop', 'path' => $storage]));

exit(new CommandRunner($app, 'cake')->run(['cake', ...array_slice($argv, 3)]));
