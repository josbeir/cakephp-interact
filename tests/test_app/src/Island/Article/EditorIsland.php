<?php
declare(strict_types=1);

namespace TestApp\Island\Article;

use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\UpdatedPaths;
use TestApp\Model\Table\ArticlesTable;

/**
 * Owns a row's draft while authorizing current access and using normal CakePHP patch/validate/save
 * behavior.
 */
class EditorIsland extends Island
{
    protected ArticlesTable $articles;

    #[Prop]
    public int $articleId;

    #[State(writable: true)]
    public string $title = '';

    #[State]
    public bool $saved = false;

    /**
     * @var \Cake\Datasource\EntityInterface|null The request's entity, retaining errors for FormHelper.
     */
    protected ?EntityInterface $article = null;

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->articles = $this->fetchTable(ArticlesTable::class);
    }

    /**
     * Reject ownership or permission changes before reading editable content or invoking actions.
     */
    public function authorize(IslandContext $context): void
    {
        /** @var mixed $identity */
        $identity = $context->request->getAttribute('identity');
        if (!is_array($identity) || ($identity['role'] ?? null) !== 'editor' || !is_int($identity['id'] ?? null)) {
            throw new ForbiddenException('Editing is not allowed.');
        }

        try {
            $this->articles->get($this->articleId, 'ownedBy', ownerId: $identity['id']);
        } catch (RecordNotFoundException) {
            throw new ForbiddenException('Editing is not allowed.');
        }
    }

    /**
     * Only initialize from persisted data on first mount, preserving drafts through later requests.
     */
    public function mount(): void
    {
        $this->title = (string)$this->articles->get($this->articleId)->get('title');
    }

    /**
     * Clear success feedback after receiving another edit to the draft.
     *
     * @param UpdatedPaths $paths Hide stale success feedback once the server receives another edit.
     */
    public function updated(UpdatedPaths $paths): void
    {
        $this->saved = false;
    }

    /**
     * Validate the explicit draft and persist using CakePHP rather than a plugin-specific saving
     * lifecycle.
     */
    #[Action]
    public function save(): void
    {
        $articles = $this->articles;
        $article = $articles->patchEntity(
            $articles->get($this->articleId),
            ['title' => $this->title],
            ['fields' => ['title']],
        );
        $this->article = $article;
        if (!$articles->save($article)) {
            $this->setErrors($article->getErrors() ?: ['_form' => ['Unable to save the article.']]);

            return;
        }

        $this->saved = true;
        $this->dispatch('article-saved', id: $this->articleId);
        $this->dispatch("article-saved:{$this->articleId}", id: $this->articleId);
    }

    /**
     * Give FormHelper the same entity that failed validation, without adding it to the snapshot.
     *
     * @return array{article: \Cake\Datasource\EntityInterface} Server-only entity context for this render.
     */
    public function viewData(): array
    {
        return ['article' => $this->article ?? $this->articles->get($this->articleId)];
    }
}
