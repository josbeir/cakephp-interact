<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Datasource\Paging\SortField;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use Interact\Attribute\Action;
use Interact\Attribute\Pagination;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use Interact\Pagination\PaginationState;
use Interact\UpdatedPaths;
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

    protected ResourcesTable $resources;

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

    #[Pagination(
        settings: [
            'limit' => 20,
            'maxLimit' => 50,
            'order' => ['Resources.title' => 'asc', 'Resources.id' => 'asc'],
        ],
        url: ['page' => 'page', 'sort' => 'sort', 'direction' => 'direction', 'limit' => 'pageSize'],
        history: ['page', 'direction', 'limit'],
        editableLimit: true,
        defaults: ['sort' => 'title'],
    )]
    public PaginationState $paging;

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->resources = $this->fetchTable(ResourcesTable::class);
    }

    /**
     * Restart pagination when a search, filter, sort order, or page size changes.
     *
     * @param UpdatedPaths $paths Accepted browser edits.
     */
    public function updated(UpdatedPaths $paths): void
    {
        if (
            $paths->hasAny('search', 'category', 'level', 'paging.sort', 'paging.direction', 'paging.limit')
            && !$paths->has('paging.page')
        ) {
            $this->paging->page = 1;
        }
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
        $this->paging->sort = 'title';
        $this->paging->direction = 'asc';
        $this->paging->limit = 20;
        $this->paging->page = 1;
    }

    /**
     * Fetch one result page and its counts without putting database rows in signed state.
     *
     * @return array<string, mixed> Native result rows, allowed choices, and pagination display data.
     */
    public function viewData(): array
    {
        $resources = $this->paginate($this->listing(), [
            'sortableFields' => [
                'title' => 'Resources.title',
                'title-desc' => SortField::desc('Resources.title', true),
                'shortest' => SortField::asc('Resources.minutes', true),
                'longest' => SortField::desc('Resources.minutes', true),
            ],
        ]);

        return [
            'resources' => $resources,
            'total' => $resources->totalCount(),
            'librarySize' => $this->resources->find()->count(),
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
        if (!in_array($this->paging->limit, [10, 20, 50], true)) {
            throw new InvalidArgumentException('Choose 10, 20, or 50 results per page.');
        }

        return $this->resources->find(
            'listing',
            search: $this->search,
            category: $this->category,
            level: $this->level,
            sort: $this->paging->sort,
        );
    }
}
