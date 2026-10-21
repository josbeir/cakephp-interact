<?php
declare(strict_types=1);

namespace Interact\Url;

/**
 * Reads the raw scalar or indexed-list shape of one allowlisted query parameter.
 * Arbitrary nested keys are rejected without allocating a nested PHP structure.
 */
readonly class QueryValue
{
    public ?string $scalar;

    /**
     * @var list<string>|null Null means no list representation was supplied.
     */
    public ?array $items;

    public bool $valid;

    /**
     * Parse scalar and bracket forms, preserving list order and empty strings.
     */
    public function __construct(string $query, string $name)
    {
        $scalar = null;
        $items = [];
        $invalid = false;
        $list = false;
        $query = str_starts_with($query, '?') ? substr($query, 1) : $query;
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            if ($key !== $name && !str_starts_with($key, $name . '[')) {
                continue;
            }

            if (!mb_check_encoding($key, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) {
                $invalid = true;
                continue;
            }

            if ($key === $name) {
                $scalar = $value;
            } elseif (str_starts_with($key, $name . '[')) {
                $list = true;
                $match = [];
                if (!preg_match('/^\[(0|[1-9][0-9]*)?\]$/D', substr($key, strlen($name)), $match)) {
                    $invalid = true;
                    continue;
                }

                $index = $match[1] ?? (string)count($items);
                $items['index:' . $index] = $value;
            }
        }

        $expected = array_map(static fn(int $index): string => 'index:' . $index, array_keys(array_values($items)));
        $this->valid = !$invalid && (!$list || $scalar === null) && $expected === array_keys($items);
        $this->scalar = $scalar;
        $this->items = $list ? array_values($items) : null;
    }
}
