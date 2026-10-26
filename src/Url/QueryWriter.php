<?php
declare(strict_types=1);

namespace Interact\Url;

use InvalidArgumentException;

/**
 * Encodes accepted query values for native links without dropping empty containers or nulls.
 * The caller owns declaration validation and default-value omission.
 */
class QueryWriter
{
    /**
     * Serialize one accepted binding using the same marker vocabulary as the browser.
     */
    public function write(string $name, mixed $value): string
    {
        return implode('&', $this->pairs($name, $value));
    }

    /**
     * Retain empty containers and nulls that http_build_query would omit.
     *
     * @return list<string>
     */
    protected function pairs(string $name, mixed $value, int $depth = 0): array
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('Excessive query structure.');
        }

        if ($value === null) {
            return [rawurlencode($name . '[~null]') . '=1'];
        }

        if (is_array($value)) {
            if ($value === []) {
                return [rawurlencode($name . ($depth === 0 ? '' : '[~empty]')) . '=' . ($depth === 0 ? '' : '1')];
            }

            $pairs = [];
            /** @var mixed $entry */
            foreach ($value as $key => $entry) {
                array_push($pairs, ...$this->pairs($name . '[' . $key . ']', $entry, $depth + 1));
            }

            return $pairs;
        }

        $scalar = match (true) {
            is_bool($value) => (string)(int)$value,
            is_float($value) => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string)$value,
        };

        return [rawurlencode($name) . '=' . rawurlencode($scalar)];
    }
}
