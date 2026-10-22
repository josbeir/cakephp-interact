<?php
declare(strict_types=1);

namespace TestApp\Island\Composition;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Supplies two reusable date values independently bound to the owning form's draft.
 * The preset action demonstrates PHP changes reaching both parent paths without HTML replacement.
 */
class DateRangeIsland extends Island
{
    #[State(writable: true, modelable: true)]
    public string $start = '';

    #[State(writable: true, modelable: true)]
    public string $end = '';

    /**
     * Publish two PHP-owned changes through the same model contract as ordinary edits.
     */
    #[Action(render: false)]
    public function preset(): void
    {
        $this->start = '2026-01-01';
        $this->end = '2026-01-31';
    }
}
