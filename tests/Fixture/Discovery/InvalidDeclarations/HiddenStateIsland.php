<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Discovery\InvalidDeclarations;

use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Marks protected state for transport to verify that internal application fields cannot enter
 * snapshots.
 */
#[Render(template: 'unused')]
class HiddenStateIsland extends Island
{
    #[State]
    protected int $count = 0;
}
