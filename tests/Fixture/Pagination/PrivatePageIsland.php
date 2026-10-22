<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Render;
use Interact\Attribute\State;

/**
 * Intentionally omits URL ownership to verify pagination rejects non-reloadable page declarations.
 */
#[Render(template: 'island/pagination')]
class PrivatePageIsland extends PageIsland
{
    #[State(writable: true)]
    public int $page = 1;
}
