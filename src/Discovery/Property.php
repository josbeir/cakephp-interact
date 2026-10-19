<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Interact\Island;
use Interact\Runtime\Values;
use InvalidArgumentException;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Describes a declared property's transport type and browser-write capability.
 *
 * Validated JSON values are assigned through reflection without PHP scalar coercion.
 * Services, objects, static fields, and internal properties cannot become snapshot state.
 */
final readonly class Property
{
    public string $name;

    public string $type;

    public bool $nullable;

    /**
     * Reject declarations that cannot be safely reconstructed from JSON.
     */
    public function __construct(public ReflectionProperty $reflection, public bool $prop, public bool $writable)
    {
        $type = $reflection->getType();
        if (
            !$reflection->isPublic()
            || $reflection->isStatic()
            || $reflection->isReadOnly()
            || !$type instanceof ReflectionNamedType
            || !$type->isBuiltin()
            || !in_array($type->getName(), ['string', 'int', 'float', 'bool', 'array'], true)
        ) {
            throw new InvalidArgumentException(
                'Props and state require public, mutable, typed JSON-compatible properties.',
            );
        }

        $this->name = $reflection->getName();
        $this->type = $type->getName();
        $this->nullable = $type->allowsNull();
    }

    /**
     * Assign a validated value without PHP scalar coercion.
     */
    public function assign(Island $island, mixed $value): void
    {
        Values::validate($value);
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

        $this->reflection->setValue($island, $value);
    }
}
