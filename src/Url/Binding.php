<?php
declare(strict_types=1);

namespace Interact\Url;

use Interact\Attribute\State;
use Interact\Runtime\TransportNumber;
use InvalidArgumentException;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Compiles an explicit URL capability and its reload default for one writable property.
 *
 * Query values remain untrusted input; this declaration never grants resource access.
 *
 * @consistent-constructor
 */
readonly class Binding
{
    /**
     * Compile a typed query value with its immutable reload default.
     */
    public function __construct(
        public string $name,
        public string $type,
        public mixed $default,
        public bool $history = false,
        public bool $nullable = false,
    ) {
        Names::require($name);
        $this->validate($default);
    }

    /**
     * Require writable native state with a declared default and a lossless query representation.
     */
    public static function fromProperty(ReflectionProperty $property, State $state): static
    {
        $type = $property->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        if (
            !$state->writable
            || !$property->hasDefaultValue()
            || !$type instanceof ReflectionNamedType
            || !in_array($typeName, ['string', 'int', 'float', 'bool', 'array'], true)
            || (
                $type->allowsNull()
                && $typeName === 'array'
            )
        ) {
            throw new InvalidArgumentException(
                'URL state requires writable properties with declared defaults; nullable arrays are unsupported.',
            );
        }

        return new static(
            is_string($state->url) ? $state->url : $property->getName(),
            $typeName,
            $property->getDefaultValue(),
            $state->history,
            $type->allowsNull(),
        );
    }

    /**
     * Reject values that cannot round-trip through the browser and query codec.
     */
    public function validate(mixed $value): void
    {
        $valid =
            $value === null && $this->nullable
            || match ($this->type) {
                'string' => is_string($value),
                'bool' => is_bool($value),
                'int' => is_int($value) && TransportNumber::accepts($value),
                'float' => (is_float($value) || is_int($value)) && TransportNumber::accepts($value),
                'array' => is_array($value) && ArrayValue::accepts($value),
                default => false,
            };
        if (!$valid) {
            throw new InvalidArgumentException(
                'URL state supports nullable scalars, safe numbers, and nested string lists or maps.',
            );
        }
    }
}
