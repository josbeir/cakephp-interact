<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Discovery\InvalidDeclarations;

use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Island;

/**
 * Marks the mount hook as callable to verify that lifecycle methods cannot be exposed as browser
 * actions.
 */
#[Render(template: 'unused')]
class LifecycleActionIsland extends Island
{
    #[Action]
    public function mount(): void
    {
    }
}
