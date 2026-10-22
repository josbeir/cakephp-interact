<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Island;

/**
 * Omits an explicit view outside the Island namespace to verify the inference boundary.
 */
class UndeclaredIsland extends Island
{
}
