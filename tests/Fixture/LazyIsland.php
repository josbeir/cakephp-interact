<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;

/**
 * Records mount boundaries while requiring initialization to create its transported state.
 * Authorization uses the current request rather than trusting the pending mount ticket.
 */
#[AsIsland('lazy-fixture', element: 'Interact/lazy_fixture')]
class LazyIsland extends Island
{
    /**
     * @var list<string> Request lifecycle observations, never signed as state.
     */
    public static array $trace = [];

    #[Prop]
    public int $recordId;

    #[Prop]
    public ?string $label = 'Report';

    #[State]
    public int $count;

    #[State(writable: true, url: 'q')]
    public string $query = '';

    /**
     * Check current access before any deferred work.
     */
    public function authorize(IslandContext $context): void
    {
        self::$trace[] = 'authorize:' . $this->query;
        if ($context->request->getAttribute('denied') === true) {
            throw new ForbiddenException('Denied.');
        }
    }

    /**
     * Initialize state only once per successful browser mount attempt.
     */
    public function mount(): void
    {
        self::$trace[] = 'mount';
        $this->count = $this->recordId;
    }

    /**
     * Supply request-local rendering data after initialization.
     *
     * @return array<string, mixed> Additional template variables.
     */
    public function viewData(): array
    {
        self::$trace[] = 'view';

        return ['computed' => true];
    }

    /**
     * Demonstrate normal actions after activation.
     */
    #[Action]
    public function increment(): void
    {
        $this->count++;
    }
}
