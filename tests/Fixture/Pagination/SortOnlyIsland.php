<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Render;
use Interact\Attribute\State;

/**
 * Intentionally declares sorting without its direction companion to exercise declaration feedback.
 */
#[Render(template: 'island/pagination')]
class SortOnlyIsland extends PageIsland
{
    #[State(writable: true, url: true)]
    public string $sort = '';
}
