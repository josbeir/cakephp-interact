<?php
declare(strict_types=1);

use Cake\Http\ServerRequest;
use Composer\Autoload\ClassLoader;
use Interact\Authorization\IslandAuthorization;
use Interact\Discovery\Definition;
use Interact\Test\Fixture\Authorization\BridgeIsland;

/** @var ClassLoader $loader */
$loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$loader->unregister();
$isolated = new ClassLoader();
foreach ($loader->getPrefixesPsr4() as $prefix => $paths) {
    if ($prefix === 'Authorization\\') {
        continue;
    }

    $isolated->addPsr4($prefix, $paths);
}

$isolated->addClassMap(array_filter(
    $loader->getClassMap(),
    static fn(string $class): bool => !str_starts_with($class, 'Authorization\\'),
    ARRAY_FILTER_USE_KEY,
));
$isolated->register();

$definition = new Definition(BridgeIsland::class);
if (array_keys($definition->properties) !== ['count']) {
    throw new RuntimeException('Ordinary island metadata requires the optional package.');
}

try {
    new IslandAuthorization(new ServerRequest())->can('resource', 'access');
    throw new RuntimeException('A missing package must not grant access.');
} catch (LogicException $logicException) {
    echo $logicException->getMessage();
}
