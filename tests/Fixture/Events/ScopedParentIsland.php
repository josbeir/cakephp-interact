<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Events;

use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Issues reactive record-identity changes through the real preserved-child workflow.
 */
#[Render(element: 'events-parent')]
class ScopedParentIsland extends Island
{
    #[State(writable: true)]
    public int $articleId = 1;
}
