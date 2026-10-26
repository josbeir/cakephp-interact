<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use Interact\Runtime\Values;
use Interact\Url\Binding;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Describes a declared property's transport type and browser-write capability.
 *
 * Validated JSON values are assigned through reflection without PHP scalar coercion.
 * Services, objects, static fields, and internal properties cannot become snapshot state.
 */
readonly class Property
{
    public string $name;

    public string $type;

    public bool $nullable;

    public ?Binding $url;

    public bool $modelable;

    public bool $reactive;

    /**
     * Instantiate the exact prop marker without adding declaration logic to the compiler.
     *
     * @param list<ReflectionAttribute<Prop>> $attributes Exact declaration markers.
     */
    public static function propDeclaration(array $attributes): ?Prop
    {
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Reject declarations that cannot be safely reconstructed from JSON.
     */
    public function __construct(
        public ReflectionProperty $reflection,
        public bool $prop,
        public bool $writable,
        ?State $state = null,
        ?Prop $declaration = null,
    ) {
        $type = $reflection->getType();
        if (
            !$reflection->isPublic()
            || $reflection->isStatic()
            || $reflection->isReadOnly()
            || !$type instanceof ReflectionNamedType
        ) {
            throw new InvalidArgumentException(
                'Props and state require public, mutable, typed JSON-compatible properties.',
            );
        }

        $this->name = $reflection->getName();
        $this->type = $this->transportType($type);
        $this->nullable = $type->allowsNull();
        $this->url =
            $state instanceof State && $state->url !== false ? Binding::fromProperty($reflection, $state) : null;
        $this->modelable = $state->modelable ?? false;
        $this->reactive = $declaration->reactive ?? false;
    }

    /**
     * Resolve the scalar or array representation accepted by the state transport.
     */
    protected function transportType(ReflectionNamedType $type): string
    {
        if (!$type->isBuiltin() || !in_array($type->getName(), ['string', 'int', 'float', 'bool', 'array'], true)) {
            throw new InvalidArgumentException(
                'Props and state require public, mutable, typed JSON-compatible properties.',
            );
        }

        return $type->getName();
    }

    /**
     * Read the JSON representation of an initialized property.
     */
    public function read(Island $island): mixed
    {
        return $this->reflection->getValue($island);
    }

    /**
     * Publish independently owned query bindings keyed by their writable state paths.
     *
     * @return array<string, Binding>
     */
    public function bindings(): array
    {
        return array_filter([$this->name => $this->url]);
    }

    /**
     * Describe this property's browser-edit capability at its exact writable path.
     *
     * @return array<string, array{type: string, nullable: bool}>
     */
    public function writableFields(): array
    {
        return [$this->name => ['type' => $this->type, 'nullable' => $this->nullable]];
    }

    /**
     * Assign a validated value without PHP scalar coercion.
     */
    public function assign(Island $island, mixed $value): void
    {
        $this->validate($value);
        $this->reflection->setValue($island, $value);
    }

    /**
     * Check a transported value before constructing or changing application state.
     */
    public function validate(mixed $value): void
    {
        Values::validate($value);
        $this->url?->validate($value);
        $matches = match ($this->type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_int($value) || is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => false,
        };
        if (!$matches && ($value !== null || !$this->nullable)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid value for "%s": expected %s.',
                $this->name,
                $this->type,
            ));
        }
    }
}
