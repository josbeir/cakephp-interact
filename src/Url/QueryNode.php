<?php
declare(strict_types=1);

namespace Interact\Url;

use InvalidArgumentException;

/**
 * Builds one bounded raw-query tree without PHP key normalization or sparse allocation.
 *
 * Scalar/container conflicts remain invalid even when later parameters repeat a path.
 * Reserved leaves distinguish null and empty containers from ordinary string values.
 *
 * @consistent-constructor
 */
class QueryNode
{
    protected ?string $scalar = null;

    /**
     * @var array<string, static> Prefixing keys prevents PHP from coercing numeric strings.
     */
    protected array $children = [];

    /**
     * Insert a decoded path, retaining last-value-wins semantics for duplicate leaves.
     *
     * @param list<string> $path Raw bracket segments; an empty segment appends a list item.
     */
    public function insert(array $path, string $value): void
    {
        if (count($path) > 32 || ($path === [] ? $this->children !== [] : $this->scalar !== null)) {
            throw new InvalidArgumentException('Conflicting or excessive query structure.');
        }

        if ($path === []) {
            $this->scalar = $value;

            return;
        }

        $segment = array_shift($path);
        $segment = $segment === '' ? (string)count($this->children) : $segment;

        $key = 'key:' . $segment;
        $this->children[$key] ??= new static();
        $this->children[$key]->insert($path, $value);
    }

    /**
     * Decode contiguous lists and safe maps, accepting markers only as sole literal leaves.
     *
     * @return string|array<array-key, mixed>|null
     */
    public function value(): string|array|null
    {
        if ($this->scalar !== null) {
            return $this->scalar;
        }

        $result = [];
        $list = null;
        foreach ($this->children as $key => $child) {
            $segment = substr($key, 4);
            if (in_array($segment, ['~null', '~empty'], true)) {
                if (count($this->children) !== 1 || $child->scalar !== '1' || $child->children !== []) {
                    throw new InvalidArgumentException('Invalid query marker.');
                }

                return $segment === '~null' ? null : [];
            }

            $numeric = ctype_digit($segment);
            $list ??= $numeric;
            if ($list !== $numeric || ($numeric && $segment !== (string)count($result))) {
                throw new InvalidArgumentException('Mixed or sparse query array.');
            }

            $result[$segment] = $child->value();
        }

        return $result;
    }
}
