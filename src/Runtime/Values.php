<?php
declare(strict_types=1);

namespace Interact\Runtime;

use InvalidArgumentException;

/**
 * Validates the deliberately small transport value vocabulary and safe nested paths.
 */
class Values
{
    /**
     * Require a string-keyed record before passing unknown data to typed consumers.
     *
     * @return array<string, mixed> A string-keyed record, validated before typed consumers receive it.
     */
    public static function map(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Expected an island record.');
        }

        $record = [];

        /** @var mixed $entry */
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Island record keys must be strings.');
            }

            $record[$key] = $entry;
        }

        return $record;
    }

    /**
     * Validate a JSON-compatible value before signing, assigning, or emitting it.
     */
    public static function validate(mixed $value, int $depth = 0): void
    {
        if ($depth > 32 || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException(
                'Island values must be finite JSON-compatible values, at most 32 levels deep.',
            );
        }

        TransportNumber::validate($value);

        if (is_array($value)) {
            /** @var mixed $entry */
            foreach ($value as $key => $entry) {
                if (in_array((string)$key, ['__proto__', 'prototype', 'constructor'], true)) {
                    throw new InvalidArgumentException('Unsafe state key.');
                }

                static::validate($entry, $depth + 1);
            }
        }
    }

    /**
     * Parse a dotted state path and reject malformed or prototype-related segments.
     *
     * @return non-empty-list<string> Validated segments, including numeric array indices.
     */
    public static function path(string $path): array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_-]+)*$/D', $path)) {
            throw new InvalidArgumentException('Invalid state path.');
        }

        $parts = explode('.', $path);
        foreach ($parts as $part) {
            if (in_array($part, ['__proto__', 'prototype', 'constructor'], true)) {
                throw new InvalidArgumentException('Unsafe state path.');
            }
        }

        return $parts;
    }
}
