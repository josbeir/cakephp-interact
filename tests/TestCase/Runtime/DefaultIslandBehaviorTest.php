<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Runtime;

use Interact\Test\Fixture\Runtime\PlainIsland;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies default view data and rejects malformed browser-event names.
 * These boundaries run through the same declarations and runtime contracts as application islands.
 */
class DefaultIslandBehaviorTest extends TestCase
{
    public function testDefaultViewDataAndInvalidBrowserEvent(): void
    {
        $island = new PlainIsland();
        $this->assertSame([], $island->viewData());
        $this->expectException(InvalidArgumentException::class);
        $island->dispatch('bad name');
    }
}
