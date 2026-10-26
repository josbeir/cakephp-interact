<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Interact\Attribute\Pagination;
use InvalidArgumentException;

/**
 * Defines the closed scalar schema for one independently owned paging selection.
 *
 * Defaults are compiled from server declarations. Restored snapshots and browser edits
 * must match the same field set and strict scalar types before application code runs.
 */
readonly class Selection
{
    /**
     * @var array{page: int, sort: string, direction: string, limit: int} Native request defaults.
     */
    public array $defaults;

    /**
     * Compile typed defaults while keeping page-size configuration in native settings.
     */
    public function __construct(protected Pagination $declaration)
    {
        $defaults = $declaration->defaults + ['page' => 1, 'sort' => '', 'direction' => 'asc'];
        if (array_diff(array_keys($defaults), ['page', 'sort', 'direction']) !== []) {
            throw new InvalidArgumentException('Pagination defaults support page, sort, and direction only.');
        }

        $this->defaults = [
            'page' => $this->integer($defaults['page'], 'page'),
            'sort' => $this->text($defaults['sort']),
            'direction' => $this->text($defaults['direction']),
            'limit' => $this->integer($declaration->settings['limit'] ?? 20, 'limit'),
        ];
    }

    /**
     * Expose only enabled browser-controlled fields and their immutable defaults.
     *
     * @return array<string, int|string>
     */
    public function fields(): array
    {
        return $this->declaration->editableLimit ? $this->defaults : array_diff_key($this->defaults, ['limit' => true]);
    }

    /**
     * Decode an exact scalar selection without object deserialization or PHP coercion.
     *
     * @return array{page: int, sort: string, direction: string, limit: int}
     */
    public function decode(mixed $value): array
    {
        $fields = $this->fields();
        if (!is_array($value) || array_diff_key($value, $fields) !== [] || array_diff_key($fields, $value) !== []) {
            throw new InvalidArgumentException('Pagination state requires exactly its declared scalar fields.');
        }

        return [
            'page' => $this->integer($value['page'], 'page'),
            'sort' => $this->text($value['sort']),
            'direction' => $this->text($value['direction']),
            'limit' => $this->integer(
                $this->declaration->editableLimit ? $value['limit'] : $this->defaults['limit'],
                'limit',
            ),
        ];
    }

    /**
     * Require integers that retain their exact value across the browser boundary.
     */
    protected function integer(mixed $value, string $name): int
    {
        if (!is_int($value) || abs($value) > 9_007_199_254_740_991) {
            throw new InvalidArgumentException(sprintf(
                'Invalid pagination %s: expected a browser-safe integer.',
                $name,
            ));
        }

        return $value;
    }

    /**
     * Preserve strict text values, including an empty sort key for configured ordering.
     */
    protected function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Pagination sort and direction require strings.');
        }

        return $value;
    }
}
