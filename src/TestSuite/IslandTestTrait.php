<?php
declare(strict_types=1);

namespace Interact\TestSuite;

use Cake\Core\ContainerApplicationInterface;
use Cake\Core\Plugin;
use Cake\Core\PluginApplicationInterface;
use Cake\Core\PluginCollection;
use Cake\Core\TestSuite\ContainerStubTrait;
use Cake\Event\EventManager;
use Cake\Http\ServerRequest;
use Cake\Routing\RouteCollection;
use Cake\Routing\Router;
use Cake\Routing\RoutingApplicationInterface;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use LogicException;
use PHPUnit\Framework\Attributes\After;

/**
 * Adds fluent island tests to existing CakePHP test cases and fixture workflows.
 *
 * Application bootstrap, plugin services, and CakePHP's container mocks supply the
 * real factory. No HTTP middleware or hosting controller is executed.
 *
 * @require-extends \Cake\TestSuite\TestCase
 */
trait IslandTestTrait
{
    use ContainerStubTrait;

    protected ?Factory $islandTestFactory = null;

    protected ?PluginCollection $islandTestPlugins = null;

    protected ?RouteCollection $islandTestRoutes = null;

    protected ?ServerRequest $islandTestPreviousRequest = null;

    protected ?EventManager $islandTestEvents = null;

    /**
     * Mount a conventional island using the configured application and native templates.
     *
     * @param string $island Application/plugin selector or island class reference.
     * @param array<string, mixed> $props Declared mount props, never arbitrary state assignment.
     * @param \Cake\Http\ServerRequest|null $request Request attributes carry the application's normal identity.
     */
    protected function testIsland(string $island, array $props = [], ?ServerRequest $request = null): IslandTest
    {
        $factory = $this->getIslandTestFactory();
        $request ??= new ServerRequest(['url' => 'http://localhost/', 'environment' => ['REQUEST_METHOD' => 'GET']]);

        return new IslandTest($factory, $this->islandTestContext($request), $island, $props);
    }

    /**
     * Override for an application's custom update endpoint or middleware scope.
     */
    protected function islandTestContext(ServerRequest $request): IslandContext
    {
        return new IslandContext($request, 'default', Router::url(['_name' => 'interact:update']));
    }

    /**
     * Bootstrap once per test using CakePHP's configApplication() and mockService() support.
     */
    protected function getIslandTestFactory(): Factory
    {
        if ($this->islandTestFactory !== null) {
            return $this->islandTestFactory;
        }

        $this->islandTestPlugins = Plugin::getCollection();
        $this->islandTestRoutes = Router::getRouteCollection();
        $this->islandTestPreviousRequest = Router::getRequest();
        $this->islandTestEvents = EventManager::instance();
        EventManager::instance(clone $this->islandTestEvents);
        $app = $this->createApp();
        if (!$app instanceof ContainerApplicationInterface) {
            throw new LogicException('Island tests require an application with a CakePHP container.');
        }

        $app->bootstrap();
        if ($app instanceof PluginApplicationInterface) {
            $app->pluginBootstrap();
        }

        $routes = Router::createRouteBuilder('/');
        if ($app instanceof RoutingApplicationInterface) {
            $app->routes($routes);
        }

        if ($app instanceof PluginApplicationInterface) {
            $app->pluginRoutes($routes);
        }

        return $this->islandTestFactory = $app->getContainer()->get(Factory::class);
    }

    /**
     * Release application services and restore routing and plugin state after each test.
     */
    #[After]
    public function cleanupIslandTests(): void
    {
        $this->islandTestFactory = null;
        if ($this->islandTestEvents !== null) {
            EventManager::instance($this->islandTestEvents);
            $this->islandTestEvents = null;
        }

        if ($this->islandTestPlugins !== null) {
            Plugin::setCollection($this->islandTestPlugins);
            $this->islandTestPlugins = null;
        }

        $this->restoreIslandTestRouting();
    }

    /**
     * Restore the route collection and request present before application bootstrap.
     */
    protected function restoreIslandTestRouting(): void
    {
        if ($this->islandTestRoutes !== null) {
            Router::reload();
            Router::setRouteCollection($this->islandTestRoutes);
            $this->islandTestRoutes = null;
        }

        if ($this->islandTestPreviousRequest !== null) {
            Router::setRequest($this->islandTestPreviousRequest);
            $this->islandTestPreviousRequest = null;
        }
    }
}
