<?php
declare(strict_types=1);

namespace Interact\Pagination;

/**
 * Reads native link options before CakePHP omits its defaults from the query string.
 * Interact compares these values with explicit State defaults, which may differ from paginator defaults.
 */
class LinkParameters
{
    /**
     * Preserve complete paging edits and translate CakePHP's combined sort-link syntax.
     *
     * @param array<string, mixed> $options Native helper paging options.
     * @return array{page: int, limit: int, sort: string, direction: string} Conventional typed edits.
     */
    public static function read(Paginated $result, array $options, string $format): array
    {
        $values = [
            'page' => (int)($options['page'] ?? $result->currentPage()),
            'limit' => (int)($options['limit'] ?? $result->perPage()),
            'sort' => (string)($options['sort'] ?? $result->pagingParam('sort')),
            'direction' => (string)($options['direction'] ?? $result->pagingParam('direction') ?? 'asc'),
        ];
        $match = [];
        if ($format === 'combined' && preg_match('/^(.*)-(asc|desc)$/D', $values['sort'], $match)) {
            $values['sort'] = $match[1];
            $values['direction'] = $match[2];
        }

        return $values;
    }
}
