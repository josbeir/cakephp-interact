<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Interact\Attribute\Pagination;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Attribute\Upload;
use Interact\Event\ListenerNames;
use Interact\Island;
use Interact\Pagination\PaginationProperty;
use Interact\Upload\UploadProperty;
use Interact\Url\UrlState;
use InvalidArgumentException;
use ReflectionClass;

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
     * @var array<string, bool> Actions permitted to emit incremental updates.
     */
    public array $streamingActions;

    /** Cached listener templates and their declared prop dependencies. */
    public ListenerNames $listeners;

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
        [$properties, $uploads] = $this->compileProperties($reflection);
        $this->properties = $properties;
        $this->uploads = $uploads;
        $actions = new Actions($reflection, $properties);
        $this->actions = $actions->methods;

        $this->renderlessActions = $actions->renderless;
        $this->streamingActions = $actions->streaming;
        $this->listeners = $actions->listeners;
        new UrlState()->metadata($this, null);
    }

    /**
     * Compile explicit property ownership before actions can reference any props.
     *
     * @param ReflectionClass<Island> $reflection Concrete application declarations.
     * @return array{array<string, Property>, array<string, UploadProperty>} Transported values and request-local uploads.
     */
    protected function compileProperties(ReflectionClass $reflection): array
    {
        $properties = [];
        $uploads = [];
        foreach ($reflection->getProperties() as $property) {
            $field = $property->getName();
            $prop = $property->getAttributes(Prop::class);
            $state = $property->getAttributes(State::class);
            $upload = $property->getAttributes(Upload::class);
            $pagination = $property->getAttributes(Pagination::class);
            if ((int)($prop !== []) + (int)($state !== []) + (int)($upload !== []) + (int)($pagination !== []) > 1) {
                throw new InvalidArgumentException(
                    'A property can declare only one of Prop, State, Upload, or Pagination.',
                );
            }

            if ($pagination !== []) {
                $properties[$field] = new PaginationProperty($property, $pagination[0]->newInstance());
            }

            if ($upload !== []) {
                $uploads[$field] = new UploadProperty($property, $upload[0]->newInstance());
            }

            if ($prop !== [] || $state !== []) {
                $properties[$field] = new Property(
                    $property,
                    $prop !== [],
                    $state !== [] && $state[0]->newInstance()->writable,
                    $state === [] ? null : $state[0]->newInstance(),
                    Property::propDeclaration($prop),
                );
            }
        }

        return [$properties, $uploads];
    }

    /**
     * Return independently declared listing states without retaining request-local objects.
     *
     * @return array<string, PaginationProperty>
     */
    public function paginations(): array
    {
        return array_filter(
            $this->properties,
            static fn(Property $property): bool => $property instanceof PaginationProperty,
        );
    }

    /**
     * Read the server-owned streaming policy without allowing synchronization to enable it.
     */
    public function streams(?string $action): bool
    {
        return $action !== null && ($this->streamingActions[$action] ?? false);
    }
}
