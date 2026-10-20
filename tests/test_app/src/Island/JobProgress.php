<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;

/**
 * Simulates a finite job so polling can stop when PHP reports completion.
 *
 * Each refresh advances only this island's simulated state; no background worker or database
 * is involved. A real application would read an authorized job's current status instead.
 */
#[AsIsland('job-progress', element: 'Interact/job_progress')]
class JobProgress extends Island
{
    #[State]
    public int $progress = 0;

    #[State]
    public bool $running = true;

    /**
     * Recheck the fixture editor permission before every render or action.
     */
    public function authorize(IslandContext $context): void
    {
        /** @var mixed $identity */
        $identity = $context->request->getAttribute('identity');
        if (!is_array($identity) || ($identity['role'] ?? null) !== 'editor') {
            throw new ForbiddenException('Viewing this job is not allowed.');
        }
    }

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
