<?php
declare(strict_types=1);

namespace TestApp\Island\Article;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use Interact\UpdatedPaths;
use TestApp\Model\Table\FormArticlesTable;

/**
 * Marshals an article and new comments while retaining failed entities for FormHelper.
 *
 * Save as src/Island/Article/FormIsland.php. Only explicit drafts cross requests; entities
 * and persistence remain application-owned and request-local.
 */
class FormIsland extends Island
{
    use LocatorAwareTrait;

    protected FormArticlesTable $articles;

    /**
     * @var array{title: string, body: string, published: bool, topics: list<string>, comments: list<array{body: string, approved: bool, id?: mixed}>, id?: mixed}
     */
    #[State(writable: true)]
    public array $data = [
        'title' => '',
        'body' => '',
        'published' => false,
        'topics' => [],
        'comments' => [['body' => '', 'approved' => false]],
    ];

    #[State]
    public bool $saved = false;

    protected ?EntityInterface $article = null;

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $table = $this->fetchTable('FormArticles');
        assert($table instanceof FormArticlesTable, 'Configure the application FormArticles table.');
        $this->articles = $table;
    }

    /**
     * Clear stale save feedback when the application receives another draft edit.
     *
     * @param UpdatedPaths $paths Accepted writable paths.
     */
    public function updated(UpdatedPaths $paths): void
    {
        $this->saved = false;
    }

    /**
     * Add an application-defined blank row after capturing current form drafts.
     */
    #[Action]
    public function addComment(): void
    {
        $this->data['comments'][] = ['body' => '', 'approved' => false];
        $this->saved = false;
    }

    /**
     * Remove a draft row and keep native indexed names contiguous for marshalling.
     */
    #[Action]
    public function removeComment(int $index): void
    {
        if ($index >= 0 && $index < count($this->data['comments'])) {
            array_splice($this->data['comments'], $index, 1);
        }

        $this->saved = false;
    }

    /**
     * Patch only allowed fields and explicitly save the article and its new comments.
     */
    #[Action]
    public function save(): void
    {
        $this->saved = false;
        // This create-only form never accepts existing article or association IDs.
        // An editor must authorize every submitted association ID before marshalling.
        if (array_key_exists('id', $this->data)) {
            $this->setErrors(['_form' => ['Existing article IDs are not accepted.']]);

            return;
        }

        foreach ($this->data['comments'] as $comment) {
            if (!array_key_exists('id', $comment)) {
                continue;
            }

            $this->setErrors(['_form' => ['Existing comment IDs are not accepted.']]);

            return;
        }

        $articles = $this->articles;
        $this->article = $articles->patchEntity($articles->newEmptyEntity(), $this->data, [
            'fields' => ['title', 'body', 'published', 'topics', 'comments'],
            'associated' => ['Comments' => ['fields' => ['body', 'approved']]],
        ]);
        if (!$articles->save($this->article, ['associated' => ['Comments']])) {
            $this->setErrors($this->article->getErrors() ?: ['_form' => ['Unable to save the article.']]);

            return;
        }

        $this->saved = true;
        $this->dispatch('article-saved', ['id' => $this->article->get('id')]);
    }

    /**
     * Supply the failed entity or the current draft as FormHelper's entity context.
     *
     * Draft fields are assigned for presentation without marshalling associations
     * or running validation. The save action owns marshalling and persistence.
     *
     * @return array{article: EntityInterface}
     */
    public function viewData(): array
    {
        return ['article' => $this->article ?? $this->articles->newEmptyEntity()->patch($this->data)];
    }
}
