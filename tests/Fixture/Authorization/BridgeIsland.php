<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authorization;

use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Authorization\AuthorizationTrait;
use Interact\Island;

/**
 * Makes the opt-in virtual property observable without declaring it as transported state.
 */
#[Render(template: 'unused')]
class BridgeIsland extends Island
{
    use AuthorizationTrait;

    #[State]
    public int $count = 0;
}
