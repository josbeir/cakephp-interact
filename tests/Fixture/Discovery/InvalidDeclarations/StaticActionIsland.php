<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Discovery\InvalidDeclarations;

use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Island;

/**
 * Marks a static method as callable to verify that actions belong to a fresh island instance.
 */
#[Render(template: 'unused')]
class StaticActionIsland extends Island
{
    #[Action]
    public static function save(): void
    {
    }
}
