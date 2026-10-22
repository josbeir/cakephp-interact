<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use stdClass;

/**
 * Declares object-valued state to verify that transport excludes arbitrary PHP objects.
 */
#[Render(template: 'unused')]
class UnsupportedStateIsland extends Island
{
    #[State]
    public stdClass $record;
}
