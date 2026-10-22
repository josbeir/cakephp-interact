<?php
declare(strict_types=1);

namespace App\Island;

use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
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
     * Bound an initial page supplied by the URL before rendering results.
     */
    public function mount(): void
    {
        $this->normalizePage();
    }

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

        $this->normalizePage();
    }

    /**
     * Bound navigation against the current filtered result count.
     */
    #[Action]
    public function goTo(int $page): void
    {
        $this->page = $page;
        $this->normalizePage();
    }

    /**
     * Count and fetch the current page using the Table's composable finder.
     *
     * @return array<string, mixed> Rows and pagination values for the template.
     */
    public function viewData(): array
    {
        $query = $this->fetchTable('Resources')->find('matching', search: $this->search);
        $total = $query->count();

        return [
            'resources' => $query->limit(10)->page($this->page)->all(),
            'total' => $total,
            'pages' => max(1, (int)ceil($total / 10)),
        ];
    }

    /**
     * Normalize page input from direct links, writable edits, and history restoration.
     */
    protected function normalizePage(): void
    {
        $total = $this->fetchTable('Resources')->find('matching', search: $this->search)->count();
        $this->page = max(1, min($this->page, max(1, (int)ceil($total / 10))));
    }
}
