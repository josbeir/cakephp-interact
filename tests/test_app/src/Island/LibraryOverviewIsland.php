<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Pagination;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\Pagination\PaginationState;
use Interact\UpdatedPaths;

/**
 * Shows independently configured resource and article listings inside one island.
 * Article queries use the currently authorized owner; paging selections never grant access.
 */
class LibraryOverviewIsland extends Island
{
    use LocatorAwareTrait;

    #[Pagination(
        settings: ['limit' => 20, 'maxLimit' => 50, 'sortableFields' => ['title' => 'Resources.title']],
        url: [
            'page' => 'resourcePage',
            'sort' => 'resourceSort',
            'direction' => 'resourceDirection',
            'limit' => 'resourceSize',
        ],
        history: true,
        editableLimit: true,
    )]
    public PaginationState $resourcesPaging;

    #[Pagination(
        settings: ['limit' => 3, 'sortableFields' => ['title' => 'Articles.title']],
        url: ['page' => 'articlePage', 'sort' => 'articleSort', 'direction' => 'articleDirection'],
        history: true,
    )]
    public PaginationState $articlesPaging;

    #[State(writable: true)]
    public string $notes = '';

    protected int $ownerId = 0;

    /**
     * Re-establish the article owner on every mount and update before either query executes.
     */
    public function authorize(IslandContext $context): void
    {
        /** @var mixed $identity */
        $identity = $context->request->getAttribute('identity');
        if (!is_array($identity) || !is_int($identity['id'] ?? null)) {
            throw new ForbiddenException('Authentication required.');
        }

        $this->ownerId = $identity['id'];
    }

    /**
     * Reset only the resource listing when its page-size draft changes.
     */
    public function updated(UpdatedPaths $paths): void
    {
        if ($paths->has('resourcesPaging.limit') && !$paths->has('resourcesPaging.page')) {
            $this->resourcesPaging->page = 1;
        }
    }

    /**
     * Paginate application-owned finders independently while result rows remain request-local.
     *
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        return [
            'resources' => $this->paginate(
                $this->fetchTable('Resources')->find('listing'),
                paging: $this->resourcesPaging,
            ),
            'articles' => $this->paginate(
                $this->fetchTable('Articles')->find('listing', ownerId: $this->ownerId),
                paging: $this->articlesPaging,
            ),
        ];
    }
}
