<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Compiles one island class into its property and action allowlists.
 *
 * Registration rejects conflicting state declarations and unsupported capabilities
 * before the runtime restores values or invokes application behavior.
 */
final readonly class Definition
{
    public AsIsland $declaration;

    /**
     * @var array<string, \Interact\Discovery\Property> Props and transported state.
     */
    public array $properties;

    /**
     * @var array<string, \ReflectionMethod> Explicitly exposed application methods.
     */
    public array $actions;

    /**
     * Compile one concrete island class into validated declaration metadata.
     *
     * @param class-string<\Interact\Island> $class Concrete application island.
     */
    public function __construct(public string $class)
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(AsIsland::class);
        if (!$reflection->isInstantiable() || count($attributes) !== 1) {
            throw new InvalidArgumentException('Islands require a concrete class and one AsIsland declaration.');
        }

        $this->declaration = $attributes[0]->newInstance();
        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            $prop = $property->getAttributes(Prop::class);
            $state = $property->getAttributes(State::class);
            if ($prop !== [] && $state !== []) {
                throw new InvalidArgumentException('A property cannot be both a prop and state.');
            }

            if ($prop !== [] || $state !== []) {
                $properties[$property->getName()] = new Property(
                    $property,
                    $prop !== [],
                    $state !== [] && $state[0]->newInstance()->writable,
                );
            }
        }

        $actions = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC
            | ReflectionMethod::IS_PROTECTED
            | ReflectionMethod::IS_PRIVATE) as $method) {
            if ($method->getAttributes(Action::class) === []) {
                continue;
            }

            if (
                ($method->getModifiers() & ReflectionMethod::IS_PUBLIC) === 0
                || $method->isStatic()
                || method_exists(Island::class, $method->getName())
            ) {
                throw new InvalidArgumentException('Only public, nonstatic application methods may be actions.');
            }

            $actions[$method->getName()] = $method;
        }

        $this->properties = $properties;
        $this->actions = $actions;
    }
}
