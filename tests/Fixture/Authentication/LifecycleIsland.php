<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authentication;

use Authentication\AuthenticationServiceInterface;
use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\IslandContext;
use Interact\UpdatedPaths;

/**
 * Observes current authentication through fresh initial, deferred, and update lifecycles.
 *
 * Native services and identity observations stay in test-only storage, outside signed state.
 */
#[Render(template: 'unused')]
class LifecycleIsland extends ServiceIsland
{
    /** @var list<array{hook: string, identifier: array|string|int|null, service: AuthenticationServiceInterface}> */
    public static array $observations = [];

    /**
     * Read request-local identity information before props and state are assigned.
     */
    public function initialize(): void
    {
        $this->observe('initialize');
    }

    /**
     * Observe authentication without implicitly rejecting guest requests.
     */
    public function authorize(IslandContext $context): void
    {
        $this->observe('authorize');
    }

    /**
     * Observe the authenticated request on the first content render.
     */
    public function mount(): void
    {
        $this->observe('mount');
    }

    /**
     * Observe the current request when accepting a writable draft.
     */
    public function updated(UpdatedPaths $paths): void
    {
        $this->observe('updated');
    }

    /**
     * Read the middleware service during an explicitly declared action.
     */
    #[Action]
    public function record(): void
    {
        $this->observe('action');
    }

    /**
     * Observe rendering without exposing identity data to the template or snapshot.
     *
     * @return array<string, mixed> No additional view data.
     */
    public function viewData(): array
    {
        $this->observe('view');

        return [];
    }

    /**
     * Retain native service identity so tests can detect cached or reconstructed services.
     */
    protected function observe(string $hook): void
    {
        static::$observations[] = [
            'hook' => $hook,
            'identifier' => $this->Authentication->getIdentity()?->getIdentifier(),
            'service' => $this->Authentication,
        ];
    }
}
