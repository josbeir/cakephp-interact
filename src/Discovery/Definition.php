<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Attribute\Upload;
use Interact\Island;
use Interact\Upload\UploadProperty;
use Interact\Url\UrlState;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Compiles one island class into its property and action allowlists.
 *
 * Registration rejects conflicting state declarations and unsupported capabilities
 * before the runtime restores values or invokes application behavior.
 */
readonly class Definition
{
    public Declaration $declaration;

    /**
     * Owning plugin of the class, independently of an explicitly selected view's plugin.
     */
    public ?string $plugin;

    /**
     * @var array<string, \Interact\Discovery\Property> Props and transported state.
     */
    public array $properties;

    /**
     * @var array<string, UploadProperty> Request-local file declarations, excluded from JSON state.
     */
    public array $uploads;

    /**
     * @var array<string, \ReflectionMethod> Explicitly exposed application methods.
     */
    public array $actions;

    /**
     * @var array<string, bool> Cached rendering policy for explicitly declared actions.
     */
    public array $renderlessActions;

    /**
     * Compile one concrete island class into validated declaration metadata.
     *
     * @param class-string<\Interact\Island> $class Concrete application island.
     * @param string|null $name Resolved public name, or inferred for explicit registration.
     * @throws \InvalidArgumentException When declarations conflict or no conventional template can be inferred.
     */
    public function __construct(public string $class, ?string $name = null)
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Render::class);
        if (!$reflection->isInstantiable() || !$reflection->isSubclassOf(Island::class)) {
            throw new InvalidArgumentException('Islands require a concrete class extending Island.');
        }

        $render = isset($attributes[0]) ? $attributes[0]->newInstance() : new Render();
        $rendering = new Template($reflection, $render, $name);
        $this->plugin = $rendering->plugin;
        $this->declaration = $rendering->declaration;
        $properties = [];
        $uploads = [];
        foreach ($reflection->getProperties() as $property) {
            $prop = $property->getAttributes(Prop::class);
            $state = $property->getAttributes(State::class);
            $upload = $property->getAttributes(Upload::class);
            if ((int)($prop !== []) + (int)($state !== []) + (int)($upload !== []) > 1) {
                throw new InvalidArgumentException('A property can declare only one of Prop, State, or Upload.');
            }

            if ($upload !== []) {
                $uploads[$property->getName()] = new UploadProperty($property, $upload[0]->newInstance());
            }

            if ($prop !== [] || $state !== []) {
                $properties[$property->getName()] = new Property(
                    $property,
                    $prop !== [],
                    $state !== [] && $state[0]->newInstance()->writable,
                    $state === [] ? null : $state[0]->newInstance(),
                    Property::propDeclaration($prop),
                );
            }
        }

        $actions = [];
        $renderless = [];
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
            $renderless[$method->getName()] = !$method->getAttributes(Action::class)[0]->newInstance()->render;
        }

        $this->properties = $properties;
        $this->uploads = $uploads;
        $this->actions = $actions;
        $this->renderlessActions = $renderless;
        new UrlState()->metadata($this, null);
    }
}
