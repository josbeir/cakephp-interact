<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Render;

/**
 * Declares another class under an existing alias so collision handling can be tested.
 */
#[Render(template: 'Islands/counter')]
class DuplicateIsland extends CounterIsland
{
}
