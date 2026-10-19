<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;
use stdClass;

/**
 * Declares object-valued state to verify that transport excludes arbitrary PHP objects.
 */
#[AsIsland('unsupported-state', template: 'Islands/counter')]
class UnsupportedStateIsland extends Island
{
    #[State]
    public stdClass $record;
}
