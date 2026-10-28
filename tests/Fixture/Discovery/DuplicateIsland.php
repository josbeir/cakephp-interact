<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Discovery;

use Interact\Attribute\Render;
use Interact\Test\Fixture\Runtime\CounterIsland;

/**
 * Supplies a distinct class for explicit registration-name collision checks.
 */
#[Render(template: 'unused')]
class DuplicateIsland extends CounterIsland {}
