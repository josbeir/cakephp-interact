<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Pagination;
use Interact\Attribute\Render;
use Interact\Island;
use Interact\Pagination\PaginationState;

/**
 * Intentionally declares nullable paging state to verify the explicit object contract.
 */
#[Render(template: 'island/Pagination/resource_listing')]
class NullablePagingIsland extends Island
{
    #[Pagination]
    public ?PaginationState $paging;
}
