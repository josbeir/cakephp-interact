<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Datasource\EntityInterface;
use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use TestApp\Model\Table\FormArticlesTable;

/**
 * Saves explicit article drafts while demonstrating state updates without HTML replacement.
 * Failed entities remain request-local so FormHelper can render and clear validation errors.
 */
class DraftEditorIsland extends Island
{
    protected FormArticlesTable $articles;

    /**
     * @var array{title: string, body: string} Application-owned editable values.
     */
    #[State(writable: true)]
    public array $data = ['title' => 'A working draft', 'body' => 'Edit this article and save a draft.'];

    #[State]
    public ?int $articleId = null;

    #[State]
    public int $savedCount = 0;

    #[State]
    public int $signals = 0;

    protected ?EntityInterface $article = null;

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->articles = $this->fetchTable(FormArticlesTable::class);
    }

    /**
     * Authorize both the initial form and every later action against the current demo role.
     */
    public function authorize(IslandContext $context): void
    {
        /** @var mixed $identity */
        $identity = $context->request->getAttribute('identity');
        if (!is_array($identity) || ($identity['role'] ?? null) !== 'editor') {
            throw new ForbiddenException('Draft editing requires an editor.');
        }
    }

    /**
     * Marshal allowed fields and explicitly save; render failures through the failed entity.
     */
    #[Action(render: false)]
    public function saveDraft(): void
    {
        $table = $this->articles;
        $entity = $this->articleId === null ? $table->newEmptyEntity() : $table->get($this->articleId);
        $this->article = $table->patchEntity($entity, $this->data, ['fields' => ['title', 'body'], 'associated' => []]);
        if (!$table->save($this->article, ['associated' => []])) {
            $this->setErrors($this->article->getErrors() ?: ['_form' => ['Unable to save the draft.']]);

            return;
        }

        $this->articleId = (int)$this->article->get('id');
        $this->savedCount++;
        $this->dispatch('draft-saved', id: $this->articleId);
    }

    /**
     * Demonstrate a conditional rendering choice and a response-local browser event.
     */
    #[Action]
    public function signal(int $count = 1): void
    {
        $this->signals += $count;
        $this->dispatch('draft-signal', count: $this->signals);
        if ($count === 1) {
            $this->skipRender();
        }
    }

    /**
     * Return the failed entity or a presentation-only entity for the current draft.
     *
     * @return array{article: EntityInterface}
     */
    public function viewData(): array
    {
        return ['article' => $this->article ?? $this->articles->newEmptyEntity()->patch($this->data)];
    }
}
