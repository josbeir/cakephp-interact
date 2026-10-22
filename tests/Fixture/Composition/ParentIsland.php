<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Composition;

use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Supplies typed parent drafts and configurable mount inputs to composition integration tests.
 * The child remains independently stateful across every parent render.
 */
#[Render(template: 'island/composition_parent')]
class ParentIsland extends Island
{
    #[Prop]
    public string $loading = 'eager';

    #[State(writable: true)]
    public string $scope = 'allowed';

    /**
     * @var array<string, mixed> Initialized writable sources for child models.
     */
    #[State(writable: true)]
    public array $data = ['value' => '', 'selected' => null, 'flag' => false, 'count' => 0, 'items' => []];
}
