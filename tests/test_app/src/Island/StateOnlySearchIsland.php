<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Pagination;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Pagination\PaginationState;

/**
 * Demonstrates the resource listing with drafts and pagination entirely outside URL state.
 * Application finders, controls, and persistence boundaries match the URL-bound listing.
 */
#[Render(template: 'island/resource_search')]
class StateOnlySearchIsland extends ResourceSearchIsland
{
    #[State(writable: true)]
    public string $search = '';

    #[State(writable: true)]
    public string $category = '';

    #[State(writable: true)]
    public string $level = '';

    #[Pagination(
        settings: [
            'limit' => 20,
            'maxLimit' => 50,
            'order' => ['Resources.title' => 'asc', 'Resources.id' => 'asc'],
        ],
        editableLimit: true,
        defaults: ['sort' => 'title'],
    )]
    public PaginationState $paging;
}
