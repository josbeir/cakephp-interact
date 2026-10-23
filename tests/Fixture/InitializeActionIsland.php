<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Island;

/**
 * Attempts to expose setup as an action so declaration compilation must reject it.
 */
#[Render(template: 'unused')]
class InitializeActionIsland extends Island
{
    #[Action]
    public function initialize(): void
    {
    }
}
