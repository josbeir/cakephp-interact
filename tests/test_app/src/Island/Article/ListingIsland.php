<?php
declare(strict_types=1);

namespace TestApp\Island\Article;

use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\UpdatedPaths;
use TestApp\Model\Table\ArticlesTable;

/**
 * Owns search/pagination state and delegates article visibility/query composition to model finders.
 */
class ListingIsland extends Island
{
    use LocatorAwareTrait;

    protected ArticlesTable $articles;

    #[State(writable: true)]
    public string $search = '';

    #[State]
    public int $page = 1;

    protected int $ownerId = 0;

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->articles = $this->fetchTable(ArticlesTable::class);
    }

    /**
     * Re-establish the current identity on every request; identity is not client state.
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
     * Return to the first page when the search draft changes.
     *
     * @param UpdatedPaths $paths A search change resets the current page.
     */
    public function updated(UpdatedPaths $paths): void
    {
        if ($paths->has('search')) {
            $this->page = 1;
        }
    }

    /**
     * Advance through a server-owned page value rather than accepting arbitrary browser
     * assignments.
     */
    #[Action]
    public function next(): void
    {
        $this->page = min($this->page + 1, $this->totalPages());
    }

    /**
     * Return toward the first page without accepting invalid negative values.
     */
    #[Action]
    public function previous(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    /**
     * Re-render visible rows after an editor saves without resetting the listing draft.
     */
    #[Action(on: 'article-saved')]
    public function articleSaved(): void {}

    /**
     * Load the visible article page through model finders without serializing query results.
     *
     * @return array<string, mixed> Query results are render data, not serialized state.
     */
    public function viewData(): array
    {
        return [
            'totalPages' => $this->totalPages(),
            'articles' => $this->articles
                ->find(
                    'listing',
                    ownerId: $this->ownerId,
                    search: $this->search,
                )
                ->limit(3)
                ->page($this->page)
                ->all(),
        ];
    }

    /**
     * Bound pagination to the current visible search results, including an empty first page.
     */
    protected function totalPages(): int
    {
        $count = $this->articles->find('listing', ownerId: $this->ownerId, search: $this->search)->count();

        return max(1, (int)ceil($count / 3));
    }
}
