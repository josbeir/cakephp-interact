<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Discovery\InvalidDeclarations;

use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Island;

/**
 * Marks a protected method as callable to verify that browser actions must be public application
 * methods.
 */
#[Render(template: 'unused')]
class HiddenActionIsland extends Island
{
    #[Action]
    protected function save(): void {}
}
