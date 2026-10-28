<?php
declare(strict_types=1);

namespace Interact\Runtime;

use InvalidArgumentException;

/**
 * Shares the browser numeric range across JSON transport and URL codecs.
 * State, arguments, and event details reject unsafe numeric identifiers before encoding;
 * strings remain available for larger identifiers without implicit conversion.
 */
class TransportNumber
{
    /** Browser JSON numbers cannot preserve integer identifiers outside this range. */
    protected const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    /**
     * Require finite numbers within the browser's lossless integer range.
     */
    public static function accepts(int|float $value): bool
    {
        return is_finite((float)$value) && abs($value) <= static::MAX_SAFE_INTEGER;
    }

    /**
     * Reject unsupported numeric values, leaving other JSON types to the value validator.
     */
    public static function validate(mixed $value): void
    {
        if (!is_int($value) && !is_float($value)) {
            return;
        }

        if (!static::accepts($value)) {
            throw new InvalidArgumentException(
                'Island numbers exceed browser precision. Use strings for larger identifiers.',
            );
        }
    }
}
