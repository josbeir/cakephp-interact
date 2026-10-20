<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Makes DOM ownership visible by rendering the same PHP values inside and outside protection.
 *
 * Replacing the protected boundary explicitly gives PHP ownership of its initial markup again.
 */
#[AsIsland('morph-protection', element: 'Interact/morph_protection')]
class MorphProtection extends Island
{
    #[State]
    public int $count = 0;

    #[State]
    public int $generation = 0;

    /**
     * Render new values for both panels; the browser preserves the ignored panel.
     */
    #[Action]
    public function advance(): void
    {
        $this->count++;
    }

    /**
     * Change the protected element's identity so it mounts with the latest PHP values.
     */
    #[Action]
    public function recreate(): void
    {
        $this->generation++;
    }

    /**
     * Choose a deterministic, labeled color without adding computed data to state.
     *
     * @return array<string, mixed> The color shared by both server-rendered panels.
     */
    public function viewData(): array
    {
        return ['color' => ['mint', 'violet', 'amber'][$this->count % 3]];
    }
}
