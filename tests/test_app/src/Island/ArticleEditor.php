<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use TestApp\Model\Table\ArticlesTable;

/**
 * Owns a row's draft while authorizing current access and using normal CakePHP patch/validate/save
 * behavior.
 */
#[AsIsland('article-editor', element: 'Interact/article_editor')]
class ArticleEditor extends Island
{
    use LocatorAwareTrait;

    #[Prop]
    public int $articleId;

    #[State(writable: true)]
    public string $title = '';

    #[State]
    public bool $saved = false;

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
            $this->articles()->get($this->articleId, 'ownedBy', ownerId: $identity['id']);
        } catch (RecordNotFoundException) {
            throw new ForbiddenException('Editing is not allowed.');
        }
    }

    /**
     * Only initialize from persisted data on first mount, preserving drafts through later requests.
     */
    public function mount(): void
    {
        $this->title = (string)$this->articles()->get($this->articleId)->get('title');
    }

    /**
     * Clear success feedback after receiving another edit to the draft.
     *
     * @param list<string> $paths Hide stale success feedback once the server receives another edit.
     */
    public function updated(array $paths): void
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
        $article = $this->articles()->patchEntity(
            $this->articles()->get($this->articleId),
            ['title' => $this->title],
            ['fields' => ['title']],
        );
        if (!$this->articles()->save($article)) {
            $messages = [];
            /** @var mixed $message */
            foreach ($article->getError('title') as $message) {
                if (!is_string($message)) {
                    continue;
                }

                $messages[] = $message;
            }

            $this->setErrors(['title' => $messages === [] ? ['Unable to save the article.'] : $messages]);

            return;
        }

        $this->saved = true;
        $this->dispatch('article-saved', ['id' => $this->articleId]);
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
