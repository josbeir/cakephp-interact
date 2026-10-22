<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Composition;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\UpdatedPaths;

/**
 * Observes composition lifecycle ordering and typed model values independently of application queries.
 * Denied parent scopes must never reach hooks, actions, or rendering.
 */
#[Render(template: 'island/composition_probe')]
class ProbeIsland extends Island
{
    public static int $constructed = 0;

    #[Prop(reactive: true)]
    public string $scope = 'allowed';

    #[Prop]
    public int $identity = 7;

    #[State(writable: true, modelable: true)]
    public string $value = '';

    #[State(writable: true, modelable: true)]
    public ?int $selected = null;

    #[State(writable: true, modelable: true)]
    public bool $flag = false;

    #[State(writable: true, modelable: true)]
    public int $count = 0;

    /**
     * @var array<string, mixed> Composite modelable JSON values.
     */
    #[State(writable: true, modelable: true)]
    public array $items = [];

    #[State(writable: true)]
    public string $notes = '';

    /**
     * @var list<string> Paths accepted by the current update hook.
     */
    #[State]
    public array $changed = [];

    #[State]
    public int $mounts = 0;

    #[State]
    public int $calls = 0;

    /**
     * Expose construction so invalid capabilities can be proven to fail earlier.
     */
    public function __construct()
    {
        self::$constructed++;
    }

    /**
     * Check newly supplied props and bound values before application hooks run.
     */
    public function authorize(IslandContext $context): void
    {
        if ($this->scope === 'denied' || $this->value === 'denied') {
            throw new ForbiddenException('Composition access denied.');
        }
    }

    /**
     * Count initialization separately from later prop refreshes.
     */
    public function mount(): void
    {
        $this->mounts++;
    }

    /**
     * Record prop and writable changes in one authorized hook.
     *
     * @param UpdatedPaths $paths Changed top-level names and writable paths.
     */
    public function updated(UpdatedPaths $paths): void
    {
        $this->changed = $paths->toArray();
    }

    /**
     * Demonstrate an explicit action runs once after the verified input update.
     */
    #[Action(render: false)]
    public function normalize(): void
    {
        $this->value = strtoupper(trim($this->value));
        $this->calls++;
    }
}
