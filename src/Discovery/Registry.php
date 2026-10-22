<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Cake\Container\ContainerInterface;
use Cake\Core\App;
use Cake\Core\Plugin;
use Interact\Island;
use InvalidArgumentException;
use ReflectionClass;
use function Cake\Core\pluginSplit;

/**
 * Resolves conventional application and plugin island names on demand.
 *
 * Only compiled declarations are retained. Every mount and update receives a fresh
 * container-created instance; signed updates cannot choose arbitrary PHP class names.
 */
class Registry
{
    /**
     * @var array<string, \Interact\Discovery\Definition> Compiled class metadata only.
     */
    protected array $definitions = [];

    /**
     * The container may be omitted for dependency-free islands in standalone views.
     */
    public function __construct(protected readonly ?ContainerInterface $container = null)
    {
    }

    /**
     * Register a nonstandard class or explicit name without replacing another island.
     *
     * @param string $class Island class, checked before metadata compilation.
     * @param string|null $name Optional public selector for an explicitly registered class.
     * @throws \InvalidArgumentException When the class, selector, or name registration is invalid.
     */
    public function register(string $class, ?string $name = null): void
    {
        if (!is_subclass_of($class, Island::class)) {
            throw new InvalidArgumentException('Registered islands must extend Island.');
        }

        $definition = new Definition($class, $name);
        $name = Name::normalize($definition->declaration->name);
        if (isset($this->definitions[$name]) && $this->definitions[$name]->class !== $class) {
            throw new InvalidArgumentException(sprintf('Duplicate island name "%s".', $name));
        }

        $this->definitions[$name] = $definition;
    }

    /**
     * Resolve bounded CakePHP names beneath Island namespaces, never arbitrary FQCNs.
     *
     * @throws \InvalidArgumentException When the name is invalid or no concrete island can be resolved.
     */
    public function definition(string $alias): Definition
    {
        $name = Name::normalize($alias);
        if (!isset($this->definitions[$name])) {
            [$plugin] = pluginSplit($name);
            if ($plugin !== null && !Plugin::isLoaded($plugin)) {
                throw new InvalidArgumentException(sprintf('Unknown island plugin "%s".', $plugin));
            }

            $class = App::className($name, 'Island', 'Island');
            if ($class === null) {
                throw new InvalidArgumentException(sprintf('Unknown island "%s".', $alias));
            }

            $this->register($class, $name);
        }

        return $this->definitions[$name];
    }

    /**
     * Construct a fresh instance even when its dependency registration is shared.
     */
    public function create(Definition $definition): Island
    {
        /** @var mixed $instance */
        $instance = $this->container instanceof ContainerInterface
            ? $this->container->getNew($definition->class)
            : new ReflectionClass($definition->class)->newInstance();
        if (!$instance instanceof Island) {
            throw new InvalidArgumentException('The container must return an Island instance.');
        }

        return $instance;
    }
}
