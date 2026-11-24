<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Loading;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;

/**
 * Records mount boundaries while requiring initialization to create its transported state.
 * Authorization uses the current request rather than trusting the pending mount ticket.
 */
#[Render(element: 'unused')]
class LazyIsland extends Island
{
    /**
     * @var list<string> Request lifecycle observations, never signed as state.
     */
    protected static array $trace = [];

    #[Prop]
    public int $recordId;

    #[Prop]
    public ?string $label = 'Report';

    #[State]
    public int $count;

    #[State(writable: true, url: 'q')]
    public string $query = '';

    /**
     * Clear observations before exercising a lifecycle.
     */
    public static function resetTrace(): void
    {
        static::$trace = [];
    }

    /**
     * Return the recorded lifecycle steps in execution order.
     *
     * @return list<string>
     */
    public static function trace(): array
    {
        return static::$trace;
    }

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
