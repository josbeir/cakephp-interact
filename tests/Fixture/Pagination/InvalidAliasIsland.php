<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Pagination;
use Interact\Attribute\Render;
use Interact\Pagination\PaginationState;

/**
 * Intentionally binds an unknown paging field to verify declaration feedback.
 */
#[Render(template: 'island/Pagination/resource_listing')]
class InvalidAliasIsland extends PageIsland
{
    #[Pagination(url: ['missing' => 'sort'])]
    public PaginationState $paging;
}
