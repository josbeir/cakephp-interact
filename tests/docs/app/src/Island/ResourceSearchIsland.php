<?php
declare(strict_types=1);

namespace App\Island;

use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Searches a resource Table and renders one result page without transporting the rows.
 *
 * Save as src/Island/ResourceSearchIsland.php.
 */
class ResourceSearchIsland extends Island
{
    use LocatorAwareTrait;

    #[State(writable: true, url: 'q')]
    public string $search = '';

    #[State(writable: true, url: true, history: true)]
    public int $page = 1;

    /**
     * @var array<string, mixed> Native page size and stable ordering.
     */
    protected array $paginate = ['limit' => 10, 'order' => ['Resources.id' => 'asc']];

    /**
     * Restart pagination when the search changes.
     *
     * @param list<string> $paths Changed writable paths.
     */
    public function updated(array $paths): void
    {
        if (in_array('search', $paths, true) && !in_array('page', $paths, true)) {
            $this->page = 1;
        }
    }

    /**
     * Count and fetch the current page using the Table's composable finder.
     *
     * @return array<string, mixed> Rows and pagination values for the template.
     */
    public function viewData(): array
    {
        $query = $this->fetchTable('Resources')->find('matching', search: $this->search);

        return ['resources' => $this->paginate($query)];
    }
}
