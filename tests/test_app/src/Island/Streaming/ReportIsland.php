<?php
declare(strict_types=1);

namespace TestApp\Island\Streaming;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Streams a report as text and escaped native elements while keeping the final report in state.
 *
 * The sample deliberately paces its results to make incremental delivery visible.
 * Real applications emit results as their own work produces them.
 */
class ReportIsland extends Island
{
    #[State]
    public string $report = '';

    /**
     * @var list<array{title: string, description: string}> Completed application results.
     */
    #[State]
    public array $sections = [];

    /**
     * Emit each sample result and retain it for the final authoritative render.
     */
    #[Action(stream: true)]
    public function generate(): void
    {
        $this->report = '';
        $this->sections = [];
        $this->stream('report', '', replace: true);
        $this->streamElement('rows', 'streaming_start', replace: true);
        $this->streamEvent('progress', ['percent' => 0]);
        $sections = [
            ['title' => 'CakePHP templates', 'description' => 'Rendered on the server. Delivered as it happens.'],
            ['title' => 'Escaped <application> values', 'description' => 'Real data, safely displayed as text.'],
            ['title' => 'Signed final state', 'description' => 'The completed report survives the final render.'],
        ];
        foreach ($sections as $index => $section) {
            // Demo pacing only: application work should stream results when they become available.
            usleep(600_000);
            $content = sprintf("%02d  %s\n    %s\n\n", $index + 1, $section['title'], $section['description']);
            $this->report .= $content;
            $this->sections[] = $section;
            $this->stream('report', $content);
            $this->streamElement('rows', 'streaming_row', ['index' => $index + 1, ...$section], replace: $index === 0);
            $this->streamEvent('progress', ['percent' => (int)round((($index + 1) / 3) * 100)]);
        }
    }
}
