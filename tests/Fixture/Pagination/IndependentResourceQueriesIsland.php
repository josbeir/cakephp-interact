<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Interact\Attribute\Pagination;
use Interact\Attribute\Render;
use Interact\Pagination\PaginationState;

/**
 * Exercises independent queries against the same Table using two owned paging states.
 * Authorization is inherited from the basic fixture and applies before either query runs.
 */
#[Render(template: 'island/Pagination/independent_resource_queries')]
class IndependentResourceQueriesIsland extends PageIsland
{
    #[Pagination(
        settings: ['limit' => 2, 'order' => ['Resources.id' => 'desc']],
        url: ['page' => 'other_page', 'sort' => 'other_sort', 'direction' => 'other_direction'],
        history: true,
    )]
    public PaginationState $other;

    /**
     * Evaluate each listing once with its own configuration and normalize both before rendering.
     *
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        return [
            'first' => $this->paginate(
                $this->fetchTable('Resources')
                    ->find('matching', search: $this->search)
                    ->find('filtered', category: 'cakephp'),
                paging: $this->paging,
            ),
            'second' => $this->paginate(
                $this->fetchTable('Resources')->find('matching', search: 'keyboard'),
                paging: $this->other,
            ),
        ];
    }
}
