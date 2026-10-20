<?php
declare(strict_types=1);

namespace Interact\Validation;

use InvalidArgumentException;

/**
 * Converts application validation errors into browser-visible message lists.
 *
 * CakePHP rule names stay on the original entity. Nested field and association
 * errors become dotted paths without transporting entities or running validation.
 */
class ErrorNormalizer
{
    /**
     * Accept rule-keyed messages, custom message lists, and nested field errors.
     *
     * @param array<array-key, mixed> $errors Application errors, including EntityInterface::getErrors().
     *
     * @return array<string, list<string>> Messages grouped by dotted field path.
     *
     * @throws InvalidArgumentException When fields or messages violate the error contract.
     */
    public static function normalize(array $errors): array
    {
        $normalized = [];
        /** @var mixed $messages */
        foreach ($errors as $field => $messages) {
            if (!is_string($field) || $field === '' || !is_array($messages)) {
                throw new InvalidArgumentException('Validation errors must map non-empty field names to arrays.');
            }

            static::collect($messages, $field, $normalized);
        }

        return $normalized;
    }

    /**
     * Collect strings at their owning field and descend through nested error arrays.
     *
     * @param array<array-key, mixed> $errors Rule messages or nested field errors.
     * @param array<string, list<string>> $normalized Accumulated messages, including overlapping paths.
     *
     * @throws InvalidArgumentException When messages are not strings or nesting exceeds 32 levels.
     */
    protected static function collect(array $errors, string $path, array &$normalized, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('Validation errors must be at most 32 levels deep.');
        }

        /** @var mixed $error */
        foreach ($errors as $key => $error) {
            if (is_string($error)) {
                $normalized[$path][] = $error;
            } elseif (is_array($error) && (string)$key !== '') {
                static::collect($error, $path . '.' . (string)$key, $normalized, $depth + 1);
            } else {
                throw new InvalidArgumentException('Validation messages must be strings with non-empty nested paths.');
            }
        }
    }
}
