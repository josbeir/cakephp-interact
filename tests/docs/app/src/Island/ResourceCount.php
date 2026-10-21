<?php
declare(strict_types=1);

namespace App\Island;

use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\AsIsland;
use Interact\Attribute\Prop;
use Interact\Island;

/**
 * Counts matching resources when the island is rendered, including after a deferred mount.
 *
 * Save as src/Island/ResourceCount.php. The named finder belongs to ResourcesTable.
 */
#[AsIsland('resource-count', element: 'Interact/resource_count')]
class ResourceCount extends Island
{
    use LocatorAwareTrait;

    #[Prop]
    public string $search = '';

    /**
     * Keep query execution on the server and outside the initial placeholder render.
     *
     * @return array<string, mixed> Computed values for the native element.
     */
    public function viewData(): array
    {
        return ['total' => $this->fetchTable('Resources')->find('matching', search: $this->search)->count()];
    }
}
