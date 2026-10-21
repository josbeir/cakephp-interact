<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\AsIsland;

/**
 * Provides a distinct parent identity when testing children first mounted during an update.
 */
#[AsIsland('outcome-parent', element: 'outcome')]
class OutcomeParentIsland extends OutcomeIsland
{
}
