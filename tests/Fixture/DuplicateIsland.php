<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Render;

/**
 * Supplies a distinct class for explicit registration-name collision checks.
 */
#[Render(template: 'unused')]
class DuplicateIsland extends CounterIsland
{
}
