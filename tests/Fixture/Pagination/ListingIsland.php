<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Render;
use Interact\Attribute\State;

/**
 * Adds explicit writable sorting and page-size capabilities to the basic page fixture.
 * Defaults remain application-controlled and use independent URL aliases.
 */
#[Render(template: 'island/pagination')]
class ListingIsland extends PageIsland
{
    #[State(writable: true, url: 'order', history: true)]
    public string $sort = '';

    #[State(writable: true, url: 'dir', history: true)]
    public string $direction = 'asc';

    #[State(writable: true, url: 'size', history: true)]
    public int $limit = 5;
}
