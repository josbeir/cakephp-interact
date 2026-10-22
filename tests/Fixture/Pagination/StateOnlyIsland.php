<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Render;
use Interact\Attribute\State;

/**
 * Exercises fully state-owned paging, sorting, and limits with URL options omitted.
 */
#[Render(template: 'island/pagination')]
class StateOnlyIsland extends PageIsland
{
    #[State(writable: true)]
    public int $page = 1;

    #[State(writable: true)]
    public string $search = '';

    #[State(writable: true)]
    public string $sort = '';

    #[State(writable: true)]
    public string $direction = 'asc';

    #[State(writable: true)]
    public int $limit = 5;
}
