<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Events;

use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Island;

/**
 * Declares an invalid literal listener name to exercise metadata rejection.
 */
#[Render(element: 'events')]
class InvalidListenerIsland extends Island
{
    /**
     * Invalid event names must fail before constructing an application island.
     */
    #[Action(on: 'Bad event')]
    public function receive(): void {}
}
