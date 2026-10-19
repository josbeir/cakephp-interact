<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Marks shared static state for transport to verify that snapshots contain instance-owned values
 * only.
 */
#[AsIsland('static-state', template: 'Islands/counter')]
class StaticStateIsland extends Island
{
    #[State]
    public static int $count = 0;
}
