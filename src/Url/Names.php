<?php
declare(strict_types=1);

namespace Interact\Url;

use InvalidArgumentException;

/**
 * Validates application query names and signed per-instance prefixes consistently.
 * PHP must not normalize a name or reinterpret it as nested query structure.
 */
class Names
{
    /**
     * Require a safe identifier without dots, brackets, or prototype-related names.
     */
    public static function require(mixed $name): string
    {
        if (!is_string($name) || !static::valid($name)) {
            throw new InvalidArgumentException(
                'URL keys and prefixes require a non-empty identifier without dots or brackets.',
            );
        }

        return $name;
    }

    /**
     * Check query and map keys without normalizing application identifiers.
     */
    public static function valid(mixed $name): bool
    {
        return (
            is_string($name)
                && preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $name) === 1
                && !in_array($name, ['__proto__', 'constructor', 'prototype'], true)
        );
    }

    /**
     * Accept literal map keys, list indexes, append brackets, and reserved leaf markers.
     */
    public static function segment(string $segment): bool
    {
        return static::valid($segment) || preg_match('/^(?:|0|[1-9][0-9]*|~null|~empty)$/D', $segment) === 1;
    }

    /**
     * Preserve the distinction between flat query keys and explicit instance prefixes.
     */
    public static function optional(mixed $prefix): ?string
    {
        return $prefix === null ? null : static::require($prefix);
    }
}
