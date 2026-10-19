<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Cake\AttributeResolver\AttributeResolver;
use Cake\AttributeResolver\Enum\AttributeTargetType;
use Cake\Container\ContainerInterface;
use Interact\Attribute\AsIsland;
use Interact\Island;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Discovers island aliases and constructs independent application instances.
 *
 * Only compiled declarations are retained. CakePHP owns the attribute metadata cache;
 * the optional application container supplies fresh instances and their dependencies.
 * Browser requests resolve registered aliases rather than arbitrary class names.
 */
class Registry
{
    /**
     * @var array<string, \Interact\Discovery\Definition> Compiled class metadata only.
     */
    protected array $definitions = [];

    protected bool $discovered = false;

    /**
     * The container may be omitted for dependency-free islands in standalone views.
     */
    public function __construct(
        protected readonly ?ContainerInterface $container = null,
        protected readonly string $collection = 'interact',
    ) {
    }

    /**
     * Validate a discovered or explicitly registered class at the construction boundary.
     */
    public function register(string $class): void
    {
        if (!is_subclass_of($class, Island::class)) {
            throw new InvalidArgumentException('Registered islands must extend Island.');
        }

        $definition = new Definition($class);
        $alias = $definition->declaration->name;
        if (isset($this->definitions[$alias]) && $this->definitions[$alias]->class !== $class) {
            throw new InvalidArgumentException(sprintf('Duplicate island alias "%s".', $alias));
        }

        $this->definitions[$alias] = $definition;
    }

    /**
     * Resolve aliases only; browser callers cannot select arbitrary PHP classes.
     */
    public function definition(string $alias): Definition
    {
        $this->discover();

        return (
            $this->definitions[$alias] ?? throw new InvalidArgumentException(sprintf('Unknown island "%s".', $alias))
        );
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

    /**
     * Discover each request's metadata once; CakePHP owns the persistent attribute cache.
     */
    protected function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $this->discovered = true;
        if (AttributeResolver::getConfig($this->collection) === null) {
            return;
        }

        foreach (AttributeResolver::collection($this->collection)
            ->withTargetType(AttributeTargetType::CLASS_)
            ->withAttribute(AsIsland::class) as $info) {
            $this->register($info->className);
        }
    }
}
