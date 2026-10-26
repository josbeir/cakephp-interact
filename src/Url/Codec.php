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
        if (!$raw->valid || !$raw->present) {
            return $binding->default;
        }

        if ($binding->type === 'array') {
            assert(is_array($binding->default), 'Compiled URL arrays have array defaults.');
            $value = $raw->value === '' ? [] : $raw->value;

            return is_array($value) && ArrayValue::accepts($value)
                ? $this->merge($value, $binding->default)
                : $binding->default;
        }

        if ($raw->value === null && $binding->nullable) {
            return null;
        }

        if (!is_string($raw->value)) {
            return $binding->default;
        }

        return $this->scalars->read($raw->value, $binding);
    }

    /**
     * Fill omitted map entries without appending old items to a supplied list.
     *
     * @param array<array-key, mixed> $value Decoded and validated query tree.
     * @param array<array-key, mixed> $default Compiled application default.
     * @return array<array-key, mixed>
     */
    protected function merge(array $value, array $default): array
    {
        if (array_is_list($value) || array_is_list($default)) {
            return $value;
        }

        /** @var mixed $entry */
        foreach ($value as $key => $entry) {
            /** @var mixed $previous */
            $previous = $default[$key] ?? null;
            $default[$key] = is_array($entry) && is_array($previous) ? $this->merge($entry, $previous) : $entry;
        }

        return $default;
    }
}
