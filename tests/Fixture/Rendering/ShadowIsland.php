<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Rendering;

use Interact\Attribute\Render;
use Interact\Test\Fixture\Runtime\CounterIsland;

/**
 * Attempts to shadow authoritative state from view data, exercising the renderer's consistency
 * guard.
 */
#[Render(template: 'unused')]
class ShadowIsland extends CounterIsland
{
    /**
     * Return conflicting variables to exercise the state-shadowing guard.
     *
     * @return array<string, mixed> Conflicting render variables.
     */
    public function viewData(): array
    {
        return ['count' => 999];
    }
}
