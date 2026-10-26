<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Actions;

use Interact\Attribute\Render;

/**
 * Provides a distinct parent identity when testing children first mounted during an update.
 */
#[Render(element: 'Outcome')]
class OutcomeParentIsland extends OutcomeIsland
{
}
