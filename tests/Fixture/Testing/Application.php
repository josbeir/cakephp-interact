<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Testing;

use Cake\Container\ContainerInterface;
use Cake\Core\BasePlugin;
use Interact\Discovery\Registry;
use Interact\Runtime\Factory;
use Interact\Security\SnapshotCodec;
use Interact\Upload\UploadManager;
use TestApp\Application as DemoApplication;

/**
 * Supplies explicit application service mappings for public testing-API acceptance tests.
 * Real demo routes and plugin views exercise bootstrap without introducing test-only runtime behavior.
 */
class Application extends DemoApplication
{
    /**
     * Load a real plugin so selectors and native application overrides can be tested.
     */
    public function bootstrap(): void
    {
        parent::bootstrap();
        $this->addPlugin(BasePlugin::class, ['name' => 'Commerce', 'path' => dirname(__DIR__) . '/Plugins/Commerce/']);
    }

    /**
     * Keep the service, explicit fixture mapping, and application view factory replaceable.
     */
    public function services(ContainerInterface $container): void
    {
        $container->addShared(ProbeService::class);
        $container->addShared(Registry::class, static function () use ($container): Registry {
            $registry = new Registry($container);
            $registry->register(ProbeIsland::class, 'Testing');

            return $registry;
        });
        $container->addShared(Factory::class, ViewFactory::class)
            ->addArguments([Registry::class, SnapshotCodec::class, UploadManager::class]);
    }
}
