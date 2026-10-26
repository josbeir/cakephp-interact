<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Pagination;
use Interact\Attribute\Render;
use Interact\Pagination\PaginationState;

/**
 * Keeps paging out of URLs while retaining the search field’s explicit URL binding.
 */
#[Render(template: 'island/Pagination/resource_listing')]
class PrivatePageIsland extends PageIsland
{
    #[Pagination(settings: [
        'limit' => 5,
        'maxLimit' => 10,
        'order' => ['Resources.id' => 'asc'],
        'sortableFields' => ['title' => 'Resources.title'],
    ])]
    public PaginationState $paging;
}
