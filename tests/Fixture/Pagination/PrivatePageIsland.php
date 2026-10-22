<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Render;
use Interact\Attribute\State;

/**
 * Keeps paging out of URLs while retaining the search field’s explicit URL binding.
 */
#[Render(template: 'island/pagination')]
class PrivatePageIsland extends PageIsland
{
    #[State(writable: true, url: false)]
    public int $page = 1;
}
