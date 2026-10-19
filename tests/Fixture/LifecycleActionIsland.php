<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Island;

/**
 * Marks the mount hook as callable to verify that lifecycle methods cannot be exposed as browser
 * actions.
 */
#[AsIsland('lifecycle-action', template: 'Islands/counter')]
class LifecycleActionIsland extends Island
{
    #[Action]
    public function mount(): void
    {
    }
}
