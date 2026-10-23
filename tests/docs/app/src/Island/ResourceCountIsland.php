<?php
declare(strict_types=1);

namespace App\Island;

use App\Model\Table\ResourcesTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Prop;
use Interact\Island;

/**
 * Counts matching resources when the island is rendered, including after a deferred mount.
 *
 * Save as src/Island/ResourceCountIsland.php. The named finder belongs to ResourcesTable.
 */
class ResourceCountIsland extends Island
{
    use LocatorAwareTrait;

    protected ResourcesTable $resources;

    #[Prop]
    public string $search = '';

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->resources = $this->fetchTable(ResourcesTable::class);
    }

    /**
     * Keep query execution on the server and outside the initial placeholder render.
     *
     * @return array<string, mixed> Computed values for the native element.
     */
    public function viewData(): array
    {
        return ['total' => $this->resources->find('matching', search: $this->search)->count()];
    }
}
