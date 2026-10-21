<?php
declare(strict_types=1);

namespace Interact\Url;

/**
 * Converts explicit scalar query syntax without PHP's loose truthiness or numeric coercion.
 * Defaults replace malformed input independently of application-level validation.
 */
class ScalarCodec
{
    /**
     * Decode scalars using the same finite-number and boolean vocabulary as the browser.
     */
    public function read(string $value, Binding $binding): mixed
    {
        return match ($binding->type) {
            'string' => $value,
            'bool' => match ($value) {
                '1' => true,
                '0' => false,
                default => $binding->default,
            },
            'int' => is_numeric($value)
                && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value)
                && abs((float)$value) <= 9_007_199_254_740_991
                    ? (int)$value
                    : $binding->default,
            'float' => is_numeric($value)
                && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?$/D', $value)
                && is_finite((float)$value)
                    ? (float)$value
                    : $binding->default,
            default => $binding->default,
        };
    }
}
