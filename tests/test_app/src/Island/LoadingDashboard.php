<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Demonstrates pending and active child preservation during parent refresh and removal.
 */
#[AsIsland('loading-dashboard', element: 'Interact/loading_dashboard')]
class LoadingDashboard extends Island
{
    #[State]
    public int $refreshes = 0;

    #[State]
    public bool $showManual = true;

    /**
     * Refresh the parent without changing its child identities.
     */
    #[Action]
    public function refreshDashboard(): void
    {
        $this->refreshes++;
    }

    /**
     * Dispose or freshly mount the optional child through normal PHP rendering.
     */
    #[Action]
    public function toggleManual(): void
    {
        $this->showManual = !$this->showManual;
    }
}
