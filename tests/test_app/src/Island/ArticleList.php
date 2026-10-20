<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use TestApp\Model\Table\ArticlesTable;

/**
 * Owns search/pagination state and delegates article visibility/query composition to model finders.
 */
#[AsIsland('article-list', element: 'Interact/article_list')]
class ArticleList extends Island
{
    use LocatorAwareTrait;

    #[State(writable: true)]
    public string $search = '';

    #[State]
    public int $page = 1;

    protected int $ownerId = 0;

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
     * @param list<string> $paths A search change resets the current page.
     */
    public function updated(array $paths): void
    {
        if (in_array('search', $paths, true)) {
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
     * Load the visible article page through model finders without serializing query results.
     *
     * @return array<string, mixed> Query results are render data, not serialized state.
     */
    public function viewData(): array
    {
        return [
            'totalPages' => $this->totalPages(),
            'articles' => $this->articles()
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
        $count = $this->articles()->find('listing', ownerId: $this->ownerId, search: $this->search)->count();

        return max(1, (int)ceil($count / 3));
    }

    /**
     * Resolve the application's normal CakePHP model through its Table locator.
     */
    protected function articles(): ArticlesTable
    {
        $table = $this->fetchTable('Articles');
        assert($table instanceof ArticlesTable, 'The configured collaborator must satisfy its declared contract.');

        return $table;
    }
}
