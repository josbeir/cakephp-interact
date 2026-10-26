<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Realtime;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\Subscription;
use Interact\UpdatedPaths;

/**
 * Records lifecycle boundaries while selecting subscriptions from authorized accepted state.
 *
 * Request attributes simulate permission changes independently of signed browser data.
 */
#[Render(template: 'unused')]
class RealtimeFixtureIsland extends Island
{
    /** @var list<string> Observed application behavior across fresh request instances. */
    public static array $trace = [];

    #[Prop]
    public int $recordId;

    #[Prop]
    public ?string $topic = null;

    #[State(writable: true)]
    public string $draft = '';

    #[State]
    public bool $subscribed = true;

    /**
     * Record construction setup before any transported assignment.
     */
    public function initialize(): void
    {
        static::$trace[] = 'initialize';
    }

    /**
     * Check the current request before declaring protected topics.
     */
    public function authorize(IslandContext $context): void
    {
        static::$trace[] = 'authorize';
        if ($context->request->getAttribute('realtimeAllowed', true) !== true) {
            throw new ForbiddenException('Private authorization detail.');
        }
    }

    /**
     * Seed a draft only on the initial mount.
     */
    public function mount(): void
    {
        static::$trace[] = 'mount';
        $this->draft = 'initial';
    }

    /**
     * Observe accepted edits without performing persistence.
     */
    public function updated(UpdatedPaths $paths): void
    {
        static::$trace[] = 'updated';
    }

    /**
     * Observe view work separately from lease renewal.
     *
     * @return array<string, mixed> No computed values are needed for the fixture.
     */
    public function viewData(): array
    {
        static::$trace[] = 'viewData';

        return [];
    }

    /**
     * Select exact topics from the accepted resource identity.
     *
     * @return list<Subscription> Dynamic declarations after current authorization.
     */
    public function subscriptions(IslandContext $context): array
    {
        static::$trace[] = 'subscriptions';

        return $this->subscribed ? [Subscription::refresh($this->topic ?? 'records/' . $this->recordId)] : [];
    }

    /**
     * Remove subscriptions through a renderless accepted action.
     */
    #[Action(render: false)]
    public function unsubscribe(): void
    {
        $this->subscribed = false;
    }
}
