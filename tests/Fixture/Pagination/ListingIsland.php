<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Pagination;
use Interact\Attribute\Render;
use Interact\Pagination\PaginationState;

/**
 * Adds explicit writable sorting and page-size capabilities to the basic page fixture.
 * Defaults remain application-controlled and use independent URL aliases.
 */
#[Render(template: 'island/Pagination/resource_listing')]
class ListingIsland extends PageIsland
{
    #[Pagination(
        settings: [
            'limit' => 5,
            'maxLimit' => 10,
            'order' => ['Resources.id' => 'asc'],
            'sortableFields' => ['title' => 'Resources.title'],
        ],
        url: ['page' => 'p', 'sort' => 'order', 'direction' => 'dir', 'limit' => 'size'],
        history: true,
        editableLimit: true,
    )]
    public PaginationState $paging;
}
