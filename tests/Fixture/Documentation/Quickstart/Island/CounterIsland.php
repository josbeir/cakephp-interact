<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Documentation\Quickstart\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Keeps the quickstart counter executable in public testing examples.
 * Only the declared increment action can change its transported count.
 */
class CounterIsland extends Island
{
    #[State]
    public int $count = 0;

    /**
     * Increment the count without accepting arbitrary browser state writes.
     */
    #[Action]
    public function increment(): void
    {
        $this->count++;
    }
}
