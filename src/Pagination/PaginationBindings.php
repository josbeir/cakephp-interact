<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Interact\Attribute\Pagination;
use Interact\Url\Binding;
use InvalidArgumentException;

/**
 * Compiles explicitly owned paging query aliases and per-field history policy.
 *
 * Query ownership remains flat and independent of the PHP object's property name.
 * The normal island mount prefix is applied later by URL-state synchronization.
 */
readonly class PaginationBindings
{
    /**
     * Retain immutable declaration options without retaining an island instance.
     */
    public function __construct(protected Pagination $declaration) {}

    /**
     * Publish typed scalar bindings and reject undeclared paging capabilities.
     *
     * @param array<string, int|string> $fields Enabled paging defaults.
     * @return array<string, Binding>
     */
    public function compile(string $property, array $fields): array
    {
        $aliases = match ($this->declaration->url) {
            true => array_combine(array_keys($fields), array_keys($fields)),
            false => [],
            default => $this->declaration->url,
        };
        if (array_diff_key($aliases, $fields) !== []) {
            throw new InvalidArgumentException('Pagination URL aliases must name enabled paging fields.');
        }

        $history = $this->declaration->history;
        if (
            (
                $history === true
                && $aliases === []
            )
            || (
                is_array($history)
                && array_diff($history, array_keys($aliases)) !== []
            )
        ) {
            throw new InvalidArgumentException('Pagination history requires URL-bound paging fields.');
        }

        $bindings = [];
        foreach ($aliases as $name => $alias) {
            $bindings[$property . '.' . $name] = new Binding(
                $alias,
                is_int($fields[$name]) ? 'int' : 'string',
                $fields[$name],
                $history === true || (is_array($history) && in_array($name, $history, true)),
            );
        }

        return $bindings;
    }
}
