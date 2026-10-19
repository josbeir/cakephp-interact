<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\AsIsland;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Marks one property as both prop and state to verify that ambiguous transport declarations are
 * rejected.
 */
#[AsIsland('conflicting-state', template: 'Islands/counter')]
class ConflictingStateIsland extends Island
{
    #[Prop]
    #[State]
    public int $count = 0;
}
