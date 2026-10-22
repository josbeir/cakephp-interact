<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Cake\Datasource\Paging\PaginatedInterface;
use Cake\Datasource\Paging\PaginatedResultSet;

/**
 * Keeps native CakePHP results alongside request-local link generation context.
 *
 * Templates can iterate and inspect this result normally. Neither rows nor link
 * bookkeeping enter the island snapshot.
 *
 * @extends \Cake\Datasource\Paging\PaginatedResultSet<int, mixed>
 */
class Paginated extends PaginatedResultSet
{
    /**
     * Preserve the native result contract while identifying its explicitly declared paging fields.
     *
     * @param \Cake\Datasource\Paging\PaginatedInterface<int, mixed> $result Native numeric results.
     * @param list<string> $fields Conventional browser-controlled paging names.
     */
    public function __construct(
        PaginatedInterface $result,
        public readonly Links $links,
        public readonly array $fields,
        array $parameters = [],
    ) {
        parent::__construct($result->items(), $parameters + $result->pagingParams());
    }
}
