<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Simulates a finite job so polling can stop when PHP reports completion.
 *
 * Each refresh advances only this island's simulated state; no background worker or database
 * is involved. A real application would read an authorized job's current status instead.
 */
class JobProgressIsland extends Island
{
    #[State]
    public int $progress = 0;

    #[State]
    public bool $running = true;

    /**
     * Advance the simulated job by one step and expose when polling should stop requesting.
     */
    #[Action]
    public function refreshStatus(): void
    {
        $this->progress = min(100, $this->progress + 25);
        $this->running = $this->progress < 100;
    }

    /**
     * Start the demonstration again through an explicit user action.
     */
    #[Action]
    public function restart(): void
    {
        $this->progress = 0;
        $this->running = true;
    }
}
