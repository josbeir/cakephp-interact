<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authentication;

use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Authentication\AuthenticationTrait;
use Interact\Island;

/**
 * Exposes the native service without declaring authentication data as browser state.
 */
#[Render(template: 'unused')]
class ServiceIsland extends Island
{
    use AuthenticationTrait;

    #[State(writable: true)]
    public int $count = 0;
}
