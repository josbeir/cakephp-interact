<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Leaves declared state uninitialized to verify that rendering fails before emitting an incomplete
 * snapshot.
 */
#[AsIsland('uninitialized', template: 'Islands/counter')]
class UninitializedStateIsland extends Island
{
    #[State]
    public int $count;
}
