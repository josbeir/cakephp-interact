<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Container;

use Cake\Container\Container as NativeContainer;
use Cake\Core\CakeContainerBridge;
use Cake\Core\Container;
use Cake\Http\ServerRequest;
use Interact\Container\Services;
use Interact\Discovery\Registry;
use Interact\Test\Fixture\TestSuite\ProbeIsland;
use Interact\Test\Fixture\TestSuite\ProbeService;
use League\Container\ReflectionContainer;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

/**
 * Verifies CakePHP 5 container integration without requiring application autowiring setup.
 * Explicit services retain ownership, while island instances remain fresh even with cached delegates.
 */
class ServicesTest extends TestCase
{
    public function testAutowiringDoesNotCountAsAnApplicationServiceDefinition(): void
    {
        $container = new Container();
        $container->delegate(new ReflectionContainer(cacheResolutions: true));
        $this->assertTrue($container->has(ProbeService::class));
        $this->assertFalse(Services::has($container, ProbeService::class));
        $service = new ProbeService('application override');
        $container->addShared(ProbeService::class, $service);
        $this->assertTrue(Services::has($container, ProbeService::class));
        $this->assertSame($service, Services::get($container, ProbeService::class));
    }

    public function testNativeContainerBridgeRetainsExplicitServiceDefinitions(): void
    {
        $container = new CakeContainerBridge(new NativeContainer());
        $this->assertFalse(Services::has($container, ProbeService::class));
        $container->addShared(ProbeService::class);
        $this->assertTrue(Services::has($container, ProbeService::class));
        $this->assertInstanceOf(ProbeService::class, Services::get($container, ProbeService::class));
    }

    public function testUnregisteredIslandsUseApplicationDependenciesAndFreshInstances(): void
    {
        $container = new Container();
        $container->delegate(new ReflectionContainer(cacheResolutions: true));

        $service = new ProbeService('application override');
        $container->addShared(ProbeService::class, $service);
        $registry = new Registry($container);
        $registry->register(ProbeIsland::class, 'Probe');

        $definition = $registry->definition('Probe');
        $first = $registry->create($definition, new ServerRequest());
        $second = $registry->create($definition, new ServerRequest());
        $this->assertNotSame($first, $second);
        $this->assertSame(2, $service->constructions);
        $this->assertSame([0, 0], $service->initializationCounts);
    }

    public function testInvalidServiceReplacementsFailAtTheContainerBoundary(): void
    {
        $container = new Container();
        $container->addShared(ProbeService::class, new stdClass());
        $this->expectException(UnexpectedValueException::class);
        Services::get($container, ProbeService::class);
    }
}
