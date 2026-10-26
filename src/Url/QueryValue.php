<?php
declare(strict_types=1);

namespace Interact\Url;

use InvalidArgumentException;

/**
 * Parses one allowlisted binding from raw query text, independently of unrelated input.
 *
 * Bounded nodes retain exact keys and reject malformed shapes before constructing state.
 */
readonly class QueryValue
{
    public bool $present;

    public bool $valid;

    /**
     * @var string|array<array-key, mixed>|null Decoded input, before declaration validation.
     */
    public string|array|null $value;

    /**
     * Read scalar and bracket forms without normalizing keys or coercing string leaves.
     */
    public function __construct(string $query, string $name)
    {
        $node = new QueryNode();
        $present = false;
        $valid = strlen($query) <= 1_048_576;
        $query = str_starts_with($query, '?') ? substr($query, 1) : $query;
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            if ($key !== $name && !str_starts_with($key, $name . '[')) {
                continue;
            }

            $present = true;
            if (!$valid || !mb_check_encoding([$key, $value], 'UTF-8')) {
                $valid = false;
                continue;
            }

            $suffix = substr($key, strlen($name));
            if (!preg_match('/^(?:\[[^\[\]]*\])*$/D', $suffix)) {
                $valid = false;
                continue;
            }

            $segments = [];
            preg_match_all('/\[([^\[\]]*)\]/', $suffix, $segments);
            try {
                $path = array_values($segments[1]);
                if (!array_all(
                    $path,
                    static fn(string $segment): bool => (
                        $segment === ''
                        || Names::valid($segment)
                        || preg_match('/^(?:0|[1-9][0-9]*|~null|~empty)$/D', $segment) === 1
                    ),
                )) {
                    throw new InvalidArgumentException('Invalid query path.');
                }

                $node->insert($path, $value);
            } catch (InvalidArgumentException) {
                $valid = false;
            }
        }

        $decoded = null;
        if ($present && $valid) {
            try {
                $decoded = $node->value();
            } catch (InvalidArgumentException) {
                $valid = false;
            }
        }

        $this->present = $present;
        $this->valid = $valid;
        $this->value = $decoded;
    }
}
