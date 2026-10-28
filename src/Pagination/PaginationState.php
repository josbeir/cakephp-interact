<?php
declare(strict_types=1);

namespace Interact\Pagination;

/**
 * Holds a listing's typed scalar selection for the current island request.
 *
 * The Pagination declaration initializes this object before initialize(). Its explicit
 * codec transports scalar values only, never PHP objects, queries, or result rows.
 */
class PaginationState
{
    /**
     * Receive the declaration's typed defaults without retaining its server settings.
     */
    public function __construct(
        public int $page = 1,
        public string $sort = '',
        public string $direction = 'asc',
        public int $limit = 20,
    ) {}
}
