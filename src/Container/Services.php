<?php
declare(strict_types=1);

namespace Interact\Container;

use Cake\Core\ContainerInterface;
use League\Container\ReflectionContainer;
use Psr\Container\NotFoundExceptionInterface;
use UnexpectedValueException;

/**
 * Adapts CakePHP 5 service definitions and fresh island construction.
 *
 * CakePHP 5 containers expose definitions through extend(). Checking that boundary
 * preserves application service providers without treating every concrete class as configured.
 */
class Services
{
    /**
     * Resolve a behavior service and validate CakePHP 5's untyped container result.
     *
     * @template T of object
     * @param class-string<T> $class Required service contract.
     * @return T
     */
    public static function get(ContainerInterface $container, string $class): object
    {
        /** @var mixed $service */
        $service = $container->get($class);
        if (!$service instanceof $class) {
            throw new UnexpectedValueException(sprintf('Container service must implement %s.', $class));
        }

        return $service;
    }

    /**
     * Construct an uncached island through its application factory or native autowiring.
     *
     * @param class-string $class Server-owned island class.
     */
    public static function fresh(ContainerInterface $container, string $class): mixed
    {
        if (static::has($container, $class)) {
            return $container->getNew($class);
        }

        $reflection = new ReflectionContainer();
        $reflection->setContainer($container);

        return $reflection->get($class);
    }

    /**
     * Locate an explicit definition, allowing native service providers to register it first.
     */
    public static function has(ContainerInterface $container, string $id): bool
    {
        try {
            $container->extend($id);

            return true;
        } catch (NotFoundExceptionInterface) {
            return false;
        }
    }
}
