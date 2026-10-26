<?php
declare(strict_types=1);

namespace Interact\Url;

use Interact\Attribute\State;
use InvalidArgumentException;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Compiles an explicit URL capability and its reload default for one writable property.
 *
 * Query values remain untrusted input; this declaration never grants resource access.
 */
readonly class Binding
{
    public string $name;

    public string $type;

    public bool $nullable;

    public mixed $default;

    public bool $history;

    /**
     * Require writable state with a declared default and a lossless query representation.
     */
    public function __construct(ReflectionProperty $property, State $state)
    {
        $type = $property->getType();
        if (
            !$state->writable
            || !$property->hasDefaultValue()
            || !$type instanceof ReflectionNamedType
            || !in_array($type->getName(), ['string', 'int', 'float', 'bool', 'array'], true)
            || (
                $type->allowsNull()
                && $type->getName() === 'array'
            )
        ) {
            throw new InvalidArgumentException(
                'URL state requires writable properties with declared defaults; nullable arrays are unsupported.',
            );
        }

        $this->name = is_string($state->url) ? $state->url : $property->getName();
        Names::require($this->name);
        $this->type = $type->getName();
        $this->nullable = $type->allowsNull();
        $this->default = $property->getDefaultValue();
        $this->history = $state->history;
        $this->validate($this->default);
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
                'int' => is_int($value) && abs($value) <= 9_007_199_254_740_991,
                'float' => (is_float($value) || is_int($value)) && is_finite((float)$value),
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
