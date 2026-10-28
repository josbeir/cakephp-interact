<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\TestSuite;

use Cake\Container\Container;
use Cake\Core\BasePlugin;
use Cake\Core\ConsoleApplicationInterface;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\HttpApplicationInterface;
use Cake\Core\Plugin;
use Cake\Core\PluginApplicationInterface;
use Cake\Core\PluginCollection;
use Cake\Routing\RouteBuilder;
use Cake\Routing\Router;
use Cake\Routing\RoutingApplicationInterface;
use Cake\TestSuite\TestCase;
use Interact\Discovery\Registry;
use Interact\Runtime\Factory;
use Interact\Security\SnapshotCodec;
use Interact\TestSuite\IslandTestTrait;
use RuntimeException;

/**
 * Verifies collection isolation without relying on BaseApplication to replace global state.
 * Custom application loading and every bootstrap/routing hook mutate the active collections.
 */
class IslandIsolationTest extends TestCase
{
    use IslandTestTrait;

    protected HttpApplicationInterface $application;

    protected PluginCollection $previousPlugins;

    protected ?RuntimeException $bootstrapFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPlugins = Plugin::getCollection();
        Plugin::setCollection(new PluginCollection());
        $this->connectStage('Existing');
        $application = $this->createStubForIntersectionOfInterfaces([
            HttpApplicationInterface::class,
            ContainerApplicationInterface::class,
            PluginApplicationInterface::class,
            RoutingApplicationInterface::class,
        ]);
        static::assertInstanceOf(HttpApplicationInterface::class, $application);
        $this->application = $application;

        $container = new Container();
        $container->addShared(Factory::class, new Factory(new Registry(), new SnapshotCodec(str_repeat('t', 32))));
        $application->method('getContainer')->willReturn($container);
        $application->method('bootstrap')
            ->willReturnCallback(function (): void {
                $this->connectStage('Bootstrap');
                if ($this->bootstrapFailure instanceof RuntimeException) {
                    throw $this->bootstrapFailure;
                }
            });
        $application->method('pluginBootstrap')
            ->willReturnCallback(function (): void {
                $this->connectStage('PluginBootstrap');
            });
        $application->method('routes')
            ->willReturnCallback(function (): void {
                $this->connectStage('Routes');
            });
        $application->method('pluginRoutes')
            ->willReturnCallback(function (RouteBuilder $routes): RouteBuilder {
                $this->connectStage('PluginRoutes');
                $routes->connect(
                    '/_interact/update',
                    ['controller' => 'Interact', 'action' => 'update'],
                    ['_name' => 'interact:update'],
                );

                return $routes;
            });
    }

    protected function tearDown(): void
    {
        $this->cleanupIslandTests();
        Plugin::setCollection($this->previousPlugins);
        parent::tearDown();
    }

    /**
     * Model an application loader that does not use CakePHP's default collection resets.
     */
    protected function createApp(): HttpApplicationInterface|ConsoleApplicationInterface
    {
        $this->connectStage('Construction');

        return $this->application;
    }

    /**
     * Mutate both active collections so restoring a shared reference cannot hide leaks.
     */
    protected function connectStage(string $stage): void
    {
        Plugin::getCollection()->add(new BasePlugin(['name' => $stage, 'path' => __DIR__ . '/']));
        Router::createRouteBuilder('/')->connect(
            '/' . $stage,
            ['controller' => 'Articles', 'action' => 'index'],
            ['_name' => $stage],
        );
    }

    public function testCleanupPreservesOriginalCollectionContentsAcrossEveryApplicationHook(): void
    {
        $plugins = Plugin::getCollection();
        $routes = Router::getRouteCollection();
        $this->testIsland('Counter')->assertSet('count', 0);
        $this->assertTrue(Plugin::isLoaded('Construction'));
        $this->assertTrue(Plugin::isLoaded('Bootstrap'));
        $this->assertTrue(Plugin::isLoaded('PluginBootstrap'));
        $this->assertTrue(Plugin::isLoaded('Routes'));
        $this->assertTrue(Plugin::isLoaded('PluginRoutes'));
        $this->cleanupIslandTests();

        $this->assertSame($plugins, Plugin::getCollection());
        $this->assertSame($routes, Router::getRouteCollection());
        $this->assertSame(['Existing'], Plugin::loaded());
        $this->assertSame(['Existing'], array_keys(Router::getRouteCollection()->named()));
    }

    public function testRepeatedBootsCannotAccumulatePluginsOrDuplicateNamedRoutes(): void
    {
        for ($iteration = 0; $iteration < 2; $iteration++) {
            $this->testIsland('Counter')->call('adjust', 1)->assertSet('count', 1);
            $this->cleanupIslandTests();
            $this->assertSame(['Existing'], Plugin::loaded());
            $this->assertSame(['Existing'], array_keys(Router::getRouteCollection()->named()));
        }
    }

    public function testCleanupAlsoRestoresCollectionsAfterBootstrapFails(): void
    {
        $this->bootstrapFailure = new RuntimeException('Application bootstrap failed.');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Application bootstrap failed.');
        try {
            $this->testIsland('Counter');
        } finally {
            $this->cleanupIslandTests();
            $this->assertSame(['Existing'], Plugin::loaded());
            $this->assertSame(['Existing'], array_keys(Router::getRouteCollection()->named()));
        }
    }
}
