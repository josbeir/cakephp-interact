<?php
declare(strict_types=1);

namespace TestApp\Island\Article;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use TestApp\Model\Table\ArticlesTable;

/**
 * Displays an independently authorized article and listens only for its record's events.
 * A reactive ID can change the concrete listener while retaining this island's local draft.
 */
class EventSummaryIsland extends Island
{
    use LocatorAwareTrait;

    #[Prop(reactive: true)]
    public int $articleId;

    #[Prop]
    public string $label = 'Selected article';

    #[State]
    public int $refreshes = 0;

    #[State(writable: true)]
    public string $notes = '';

    protected ArticlesTable $articles;

    protected EntityInterface $article;

    /**
     * Keep the native Table request-local and outside transported state.
     */
    public function initialize(): void
    {
        $this->articles = $this->fetchTable(ArticlesTable::class);
    }

    /**
     * Reauthorize the current reactive ID before loading content or handling an event.
     */
    public function authorize(IslandContext $context): void
    {
        /** @var mixed $identity */
        $identity = $context->request->getAttribute('identity');
        if (!is_array($identity) || !is_int($identity['id'] ?? null)) {
            throw new ForbiddenException('Authentication required.');
        }

        try {
            $this->article = $this->articles->get($this->articleId, 'ownedBy', ownerId: $identity['id']);
        } catch (RecordNotFoundException) {
            throw new ForbiddenException('This article is not visible.');
        }
    }

    /**
     * Count matching events while the normal render reads the authorized article's latest title.
     */
    #[Action(on: 'article-saved:{articleId}')]
    public function articleSaved(): void
    {
        $this->refreshes++;
    }

    /**
     * Keep the entity request-local and supply only display data to the template.
     *
     * @return array{title: string} Current authorized article title.
     */
    public function viewData(): array
    {
        return ['title' => (string)$this->article->get('title')];
    }
}
