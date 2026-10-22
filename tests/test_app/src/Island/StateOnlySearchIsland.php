<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Render;
use Interact\Attribute\State;

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

    #[State(writable: true)]
    public string $sort = 'title';

    #[State(writable: true)]
    public string $direction = 'asc';

    #[State(writable: true)]
    public int $limit = 20;

    #[State(writable: true)]
    public int $page = 1;
}
