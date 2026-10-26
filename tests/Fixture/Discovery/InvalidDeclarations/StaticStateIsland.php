<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Discovery\InvalidDeclarations;

use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Marks shared static state for transport to verify that snapshots contain instance-owned values
 * only.
 */
#[Render(template: 'unused')]
class StaticStateIsland extends Island
{
    #[State]
    public static int $count = 0;
}
