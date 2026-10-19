<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Island;

/**
 * Marks a protected method as callable to verify that browser actions must be public application
 * methods.
 */
#[AsIsland('hidden-action', template: 'Islands/counter')]
class HiddenActionIsland extends Island
{
    #[Action]
    protected function save(): void
    {
    }
}
