<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\AsIsland;

/**
 * Declares another class under an existing alias so collision handling can be tested.
 */
#[AsIsland('counter', template: 'Islands/counter')]
class DuplicateIsland extends CounterIsland
{
}
