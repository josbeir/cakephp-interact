<?php
declare(strict_types=1);

namespace TestApp\Island\Article;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use TestApp\Model\Table\ArticlesTable;

/**
 * Lets the demo choose which article a preserved child listens for.
 * The child independently authorizes each accepted ID; selection never saves a record.
 */
class EventPanelIsland extends Island
{
    #[State(writable: true)]
    public int $articleId = 1;

    protected ArticlesTable $articles;

    protected int $ownerId = 0;

    /**
     * Resolve the Table before authorization without querying protected content.
     */
    public function initialize(): void
    {
        $this->articles = $this->fetchTable(ArticlesTable::class);
    }

    /**
     * Scope article choices to the current application identity.
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
     * Execute a reusable visibility finder for the native select control.
     *
     * @return array{articles: array<int, string>} Application-owned article choices.
     */
    public function viewData(): array
    {
        $articles = [];
        foreach ($this->articles->find('listing', ownerId: $this->ownerId) as $article) {
            $articles[(int)$article->get('id')] = (string)$article->get('title');
        }

        return ['articles' => $articles];
    }
}
