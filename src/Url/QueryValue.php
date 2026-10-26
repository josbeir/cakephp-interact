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

    public bool $replace;

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
        $replace = false;
        $valid = strlen($query) <= 1_048_576;
        $query = str_starts_with($query, '?') ? substr($query, 1) : $query;
        foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            if (explode('[', $key, 2)[0] !== $name) {
                continue;
            }

            $present = true;
            if (!$valid || !mb_check_encoding([$key, $value], 'UTF-8')) {
                $valid = false;
                continue;
            }

            if ($key === $name . '[~replace]') {
                $replace = true;
                $valid = $value === '1';
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
                if (!array_all($path, Names::segment(...))) {
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
        $this->valid = $valid && (!$replace || is_array($decoded));
        $this->replace = $replace;
        $this->value = $decoded;
    }
}
