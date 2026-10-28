<?php
declare(strict_types=1);

namespace Interact\Url;

use Interact\Runtime\TransportNumber;

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
                && TransportNumber::accepts((float)$value)
                    ? (int)$value
                    : $binding->default,
            'float' => is_numeric($value)
                && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?$/D', $value)
                && TransportNumber::accepts((float)$value)
                    ? (float)$value
                    : $binding->default,
            default => $binding->default,
        };
    }
}
