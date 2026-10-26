<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Pagination;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Pagination\PaginationState;

/**
 * Exercises fully state-owned paging, sorting, and limits with URL options omitted.
 */
#[Render(template: 'island/Pagination/resource_listing')]
class StateOnlyIsland extends PageIsland
{
    #[State(writable: true)]
    public string $search = '';

    #[Pagination(
        settings: [
            'limit' => 5,
            'maxLimit' => 10,
            'order' => ['Resources.id' => 'asc'],
            'sortableFields' => ['title' => 'Resources.title'],
        ],
        editableLimit: true,
    )]
    public PaginationState $paging;
}
