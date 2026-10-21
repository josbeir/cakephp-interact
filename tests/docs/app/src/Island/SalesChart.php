<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Supplies server-owned totals to an application-managed chart.
 *
 * Save as src/Island/SalesChart.php.
 */
#[AsIsland('sales-chart', element: 'Interact/sales_chart')]
class SalesChart extends Island
{
    /**
     * Chart.js data, exposed for display without accepting browser edits.
     *
     * @var array{labels: list<string>, datasets: list<array{label: string, data: list<int>}>}
     */
    #[State]
    public array $chartData = [
        'labels' => ['Monday', 'Tuesday'],
        'datasets' => [
            ['label' => 'Orders', 'data' => [12, 18]],
        ],
    ];

    /**
     * Simulate refreshed totals for this small example.
     */
    #[Action]
    public function refreshChart(): void
    {
        $this->chartData['datasets'][0]['data'][0]++;
        $this->chartData['datasets'][0]['data'][1]++;
    }
}
