<?php
declare(strict_types=1);

namespace Interact\Url;

/**
 * Decodes declared query input while distinguishing missing, empty, and list values.
 * Each malformed field falls back independently to its compiled PHP default.
 */
class Codec
{
    /**
     * Keep scalar conversion replaceable without coupling it to query-shape parsing.
     */
    public function __construct(protected readonly ScalarCodec $scalars = new ScalarCodec())
    {
    }

    /**
     * Decode a single allowlisted value from page query text.
     */
    public function read(string $query, string $name, Binding $binding): mixed
    {
        $raw = new QueryValue($query, $name);
        if (!$raw->valid) {
            return $binding->default;
        }

        if ($binding->type === 'array') {
            return $raw->items ?? ($raw->scalar === '' ? [] : $binding->default);
        }

        if ($raw->items !== null || $raw->scalar === null) {
            return $binding->default;
        }

        return $this->scalars->read($raw->scalar, $binding);
    }
}
