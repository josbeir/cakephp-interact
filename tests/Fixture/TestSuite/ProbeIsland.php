<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\TestSuite;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\UpdatedPaths;
use RuntimeException;

/**
 * Exercises application-authored island tests with real signatures and native views.
 * The service tracks request-local construction; writable drafts remain separate from locked state.
 */
#[Render(template: 'island/TestSuite/probe')]
class ProbeIsland extends Island
{
    #[Prop]
    public string $label = 'Test island';

    #[State(writable: true)]
    public string $title = 'Initial title';

    /**
     * @var array<string, mixed> Nested drafts and distinct JSON scalar values.
     */
    #[State(writable: true)]
    public array $data = [
        'nullable' => null,
        'enabled' => false,
        'zero' => 0,
        'empty' => '',
        'rows' => [['body' => 'Draft']],
    ];

    #[State]
    public int $count = 0;

    /**
     * @var list<string> Accepted paths from the most recent edit.
     */
    #[State]
    public array $paths = [];

    protected int $construction;

    /**
     * Accept the request-local observer through the application container.
     */
    public function __construct(protected ProbeService $service)
    {
    }

    /**
     * Observe fresh instance setup before mount or snapshot values are assigned.
     */
    public function initialize(): void
    {
        $this->construction = ++$this->service->constructions;
        $this->service->initializationCounts[] = $this->count;
    }

    /**
     * Recheck current request attributes on initial mounts and every update.
     */
    public function authorize(IslandContext $context): void
    {
        $this->service->requests[] = $context->request;
        if ($context->request->getAttribute('allowed', true) !== true) {
            throw new ForbiddenException('Probe access denied.');
        }
    }

    /**
     * Initialize only once so subsequent fresh instances must restore their signed state.
     */
    public function mount(): void
    {
        $this->count = 5;
    }

    /**
     * Record one batch of accepted paths for lifecycle assertions.
     *
     * @param UpdatedPaths $paths Writable edits accepted by the normal state service.
     */
    public function updated(UpdatedPaths $paths): void
    {
        $this->paths = $paths->toArray();
        $this->count++;
    }

    /**
     * Emit repeated event names with independently assertable complete details.
     */
    #[Action]
    public function record(int $amount = 1): void
    {
        $this->count += $amount;
        $this->dispatch('recorded', id: 1);
        $this->dispatch('recorded', id: 2);
        $this->dispatch('empty-event');
    }

    /**
     * Return normalized field errors without running any persistence.
     */
    #[Action]
    public function validate(): void
    {
        if ($this->title === '') {
            $this->setErrors(['title' => ['Required.'], 'data.rows.0.body' => ['Required body.']]);
            $this->dispatch('suppressed');
        }
    }

    /**
     * Accept state and events without evaluating native view data.
     */
    #[Action(render: false)]
    public function quiet(): void
    {
        $this->record();
    }

    /**
     * Terminate the chain with a native navigation instruction.
     */
    #[Action]
    public function leave(): void
    {
        $this->redirect('/done');
    }

    /**
     * Request an independently authorized download without fetching it.
     */
    #[Action]
    public function download(): void
    {
        $this->downloadUrl('/report.csv');
    }

    /**
     * Expose unexpected application failures directly to PHPUnit.
     */
    #[Action]
    public function explode(): void
    {
        throw new RuntimeException('Application action failed.');
    }

    /**
     * Supply native-only observations rather than adding services to signed state.
     *
     * @return array{construction: int, marker: string, renders: int} Request-local rendering observations.
     */
    public function viewData(): array
    {
        return [
            'construction' => $this->construction,
            'marker' => $this->service->marker,
            'renders' => ++$this->service->renders,
        ];
    }
}
