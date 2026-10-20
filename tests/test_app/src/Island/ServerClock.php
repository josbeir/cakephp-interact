<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\I18n\DateTime;
use Interact\Attribute\AsIsland;
use Interact\Island;

/**
 * Demonstrates polling computed server data without transporting it as island state.
 *
 * Every refresh uses the normal render lifecycle; the browser owns only the optional
 * auto-refresh preference, and this example performs no persistence.
 */
#[AsIsland('server-clock', element: 'Interact/server_clock')]
class ServerClock extends Island
{
    /**
     * Read the current server time anew for each initial or subsequent render.
     *
     * @return array{serverTime: string, timestamp: string} Template-only clock values.
     */
    public function viewData(): array
    {
        $now = new DateTime();

        return ['serverTime' => $now->format('H:i:s'), 'timestamp' => $now->format(DATE_ATOM)];
    }
}
