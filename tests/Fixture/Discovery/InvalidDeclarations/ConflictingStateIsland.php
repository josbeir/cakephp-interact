<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Discovery\InvalidDeclarations;

use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Marks one property as both prop and state to verify that ambiguous transport declarations are
 * rejected.
 */
#[Render(template: 'unused')]
class ConflictingStateIsland extends Island
{
    #[Prop]
    #[State]
    public int $count = 0;
}
