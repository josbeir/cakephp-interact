<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use InvalidArgumentException;
use TestApp\Model\Table\ResourcesTable;

/**
 * Coordinates a paginated resource search using model finders and explicit writable filters.
 *
 * Search choices, pagination, and reading notes travel in the snapshot. Result rows
 * and counts are queried for each render, keeping the larger dataset outside browser state.
 */
class ResourceSearchIsland extends Island
{
    use LocatorAwareTrait;

    #[Prop]
    public string $controlPrefix = 'ResourceSearch';

    #[State(writable: true)]
    public string $notes = '';

    #[State(writable: true, url: 'q')]
    public string $search = '';

    #[State(writable: true, url: true)]
    public string $category = '';

    #[State(writable: true, url: true)]
    public string $level = '';

    #[State(writable: true, url: true)]
    public string $sort = 'title';

    #[State(writable: true, url: true)]
    public int $pageSize = 20;

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
     * Restart pagination when a search, filter, sort order, or page size changes.
     *
     * @param list<string> $paths Accepted browser edits.
     */
    public function updated(array $paths): void
    {
        if (
            array_intersect($paths, ['search', 'category', 'level', 'sort', 'pageSize']) !== []
            && !in_array('page', $paths, true)
        ) {
            $this->page = 1;
        }

        $this->normalizePage();
    }

    /**
     * Move to a bounded page selected by the pagination controls.
     */
    #[Action]
    public function goTo(int $page): void
    {
        $this->page = $page;
        $this->normalizePage();
    }

    /**
     * Restore the complete library and its default display choices.
     */
    #[Action]
    public function clear(): void
    {
        $this->search = '';
        $this->category = '';
        $this->level = '';
        $this->sort = 'title';
        $this->pageSize = 20;
        $this->page = 1;
    }

    /**
     * Fetch one result page and its counts without putting database rows in signed state.
     *
     * @return array<string, mixed> Native result rows, allowed choices, and pagination display data.
     */
    public function viewData(): array
    {
        $query = $this->listing();
        $total = $query->count();

        return [
            'resources' => $query->limit($this->pageSize)->page($this->page)->all(),
            'total' => $total,
            'librarySize' => $this->resources()->find()->count(),
            'totalPages' => $this->totalPages($total),
            'first' => $total === 0 ? 0 : (($this->page - 1) * $this->pageSize) + 1,
            'last' => min($this->page * $this->pageSize, $total),
            'categories' => ResourcesTable::CATEGORIES,
            'levels' => ResourcesTable::LEVELS,
        ];
    }

    /**
     * Build a composable model query after validating the allowed page-size choices.
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\Datasource\EntityInterface>
     */
    protected function listing(): SelectQuery
    {
        if (!in_array($this->pageSize, [10, 20, 50], true)) {
            throw new InvalidArgumentException('Choose 10, 20, or 50 results per page.');
        }

        return $this->resources()->find(
            'listing',
            search: $this->search,
            category: $this->category,
            level: $this->level,
            sort: $this->sort,
        );
    }

    /**
     * Keep an empty result set on page one while bounding navigation to real pages.
     */
    protected function totalPages(int $total): int
    {
        return max(1, (int)ceil($total / $this->pageSize));
    }

    /**
     * Resolve the demo's Table through CakePHP's normal locator.
     */
    protected function resources(): ResourcesTable
    {
        $table = $this->fetchTable('Resources');
        assert($table instanceof ResourcesTable, 'The resource finder requires the demo Resources table.');

        return $table;
    }

    /**
     * Normalize page input from direct links, writable edits, and history restoration.
     */
    protected function normalizePage(): void
    {
        $this->page = max(1, min($this->page, $this->totalPages($this->listing()->count())));
    }
}
