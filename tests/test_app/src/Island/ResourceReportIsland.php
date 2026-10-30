<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use TestApp\Model\Table\ResourcesTable;

/**
 * Queries the resource library only when its deferred content is actually rendered.
 * Request identity is rechecked before loading or interacting with the report.
 */
class ResourceReportIsland extends Island
{
    protected ResourcesTable $resources;

    #[Prop]
    public string $heading = 'Resource report';

    #[State(writable: true, url: 'q')]
    public string $search = '';

    #[State]
    public int $mounts;

    #[State]
    public int $refreshes = 0;

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->resources = $this->fetchTable(ResourcesTable::class);
    }

    /**
     * Demonstrate permission changes while the placeholder is waiting.
     */
    public function authorize(IslandContext $context): void
    {
        /** @var mixed $identity */
        $identity = $context->request->getAttribute('identity');
        if (!is_array($identity) || ($identity['role'] ?? null) !== 'editor') {
            throw new ForbiddenException('Report access denied.');
        }
    }

    /**
     * Initialize transported state only after the load request is authorized.
     */
    public function mount(): void
    {
        $this->mounts = 1;
    }

    /**
     * Count matching rows through the model's composable finder.
     *
     * @return array<string, mixed> Request-local query results.
     */
    public function viewData(): array
    {
        return ['total' => $this->resources->find('matching', search: $this->search)->count()];
    }

    /**
     * Demonstrate normal actions once the pending island becomes active.
     */
    #[Action]
    public function refreshReport(): void
    {
        $this->refreshes++;
    }
}
