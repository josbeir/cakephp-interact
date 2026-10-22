<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use InvalidArgumentException;

/**
 * Demonstrates isolated mounts and typed action arguments using a server-owned counter.
 *
 * Initial values are mount props; the browser can change the count only through declared actions.
 */
class CounterIsland extends Island
{
    #[Prop]
    public string $label = 'Counter';

    #[Prop]
    public int $initial = 0;

    #[State]
    public int $count = 0;

    /**
     * Initialize this instance from its mount-only starting value.
     */
    public function mount(): void
    {
        $this->count = max(0, min(99, $this->initial));
    }

    /**
     * Accept a small set of typed increments without exposing direct state assignment.
     */
    #[Action]
    public function adjust(int $amount): void
    {
        if (!in_array($amount, [-1, 1, 5], true)) {
            throw new InvalidArgumentException('Choose an increment of -1, 1, or 5.');
        }

        $this->count = max(0, min(99, $this->count + $amount));
    }

    /**
     * Restore the starting value for this instance without affecting its neighbor.
     */
    #[Action]
    public function reset(): void
    {
        $this->mount();
    }
}
