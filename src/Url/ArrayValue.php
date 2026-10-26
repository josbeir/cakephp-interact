<?php
declare(strict_types=1);

namespace Interact\Url;

/**
 * Validates string query trees and compares defaults without coercing scalar values.
 *
 * Map insertion order does not change equality, while list order remains significant.
 */
class ArrayValue
{
    /**
     * Reserve the owning state-record level while validating string lists and maps.
     */
    public static function accepts(mixed $value, int $depth = 1): bool
    {
        if ($depth > 32) {
            return false;
        }

        if (is_string($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        $list = array_is_list($value);
        /** @var mixed $entry */
        foreach ($value as $key => $entry) {
            if ((!$list && !Names::valid($key)) || !static::accepts($entry, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare map contents independently of insertion order while retaining list order and scalar types.
     */
    public static function same(mixed $left, mixed $right): bool
    {
        if (!is_array($left) || !is_array($right)) {
            return $left === $right;
        }

        if (count($left) !== count($right) || array_is_list($left) !== array_is_list($right)) {
            return false;
        }

        /** @var mixed $entry */
        foreach ($left as $key => $entry) {
            if (!array_key_exists($key, $right) || !static::same($entry, $right[$key])) {
                return false;
            }
        }

        return true;
    }
}
