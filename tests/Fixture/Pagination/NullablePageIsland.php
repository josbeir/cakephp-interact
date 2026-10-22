<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Intentionally declares a nullable page to verify optional URL bindings retain strict paging types.
 */
#[Render(template: 'island/pagination')]
class NullablePageIsland extends Island
{
    #[State(writable: true)]
    public ?int $page = 1;
}
