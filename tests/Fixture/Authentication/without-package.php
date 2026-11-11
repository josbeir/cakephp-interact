<?php
declare(strict_types=1);

use Cake\Http\ServerRequest;
use Composer\Autoload\ClassLoader;
use Interact\Discovery\Definition;
use Interact\Test\Fixture\Authentication\ServiceIsland;

/** @var ClassLoader $loader */
$loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$loader->unregister();
$isolated = new ClassLoader();
foreach ($loader->getPrefixesPsr4() as $prefix => $paths) {
    if ($prefix === 'Authentication\\') {
        continue;
    }

    $isolated->addPsr4($prefix, $paths);
}

$isolated->addClassMap(array_filter(
    $loader->getClassMap(),
    static fn(string $class): bool => !str_starts_with($class, 'Authentication\\'),
    ARRAY_FILTER_USE_KEY,
));
$isolated->register();

$definition = new Definition(ServiceIsland::class);
if (array_keys($definition->properties) !== ['count']) {
    throw new RuntimeException('Ordinary island metadata requires the optional package.');
}

try {
    $island = new ServiceIsland()->setRequest(new ServerRequest());
    $island->Authentication->getResult();
    throw new RuntimeException('Accessing authentication requires the optional package.');
} catch (LogicException $logicException) {
    echo $logicException->getMessage();
}
