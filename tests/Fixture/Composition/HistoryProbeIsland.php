<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Composition;

use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\UpdatedPaths;

/**
 * Combines independently owned URL state with the parent-bound values of a nested input.
 * Update-hook normalization must run for model changes even during history restoration.
 */
#[Render(template: 'island/Composition/probe')]
class HistoryProbeIsland extends ProbeIsland
{
    #[State(writable: true, url: true, history: true)]
    public int $page = 1;

    #[State(writable: true, url: true)]
    public string $filter = '';

    /**
     * Normalize changed selections after authorization while retaining the observed hook paths.
     *
     * @param UpdatedPaths $paths Accepted reactive props, writable values, and restored URL properties.
     */
    public function updated(UpdatedPaths $paths): void
    {
        parent::updated($paths);
        if ($paths->has('value')) {
            $this->value = strtoupper(trim($this->value));
        }
    }
}
