<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Island;

/**
 * Marks a static method as callable to verify that actions belong to a fresh island instance.
 */
#[AsIsland('static-action', template: 'Islands/counter')]
class StaticActionIsland extends Island
{
    #[Action]
    public static function save(): void
    {
    }
}
