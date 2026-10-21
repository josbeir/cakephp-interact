<?php
declare(strict_types=1);

namespace App\Island;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Creates an article and retains the failed entity for FormHelper validation feedback.
 *
 * Save as src/Island/ArticleForm.php.
 */
#[AsIsland('article-form', element: 'Interact/article_form')]
class ArticleForm extends Island
{
    use LocatorAwareTrait;

    #[State(writable: true)]
    public string $title = '';

    #[State]
    public bool $saved = false;

    protected ?EntityInterface $article = null;

    /**
     * Validate and insert a new article through the application's Table.
     */
    #[Action]
    public function save(): void
    {
        $articles = $this->fetchTable('Articles');
        $this->saved = false;
        $this->article = $articles->newEntity(['title' => $this->title]);
        if (!$articles->save($this->article)) {
            $this->setErrors($this->article->getErrors() ?: ['_form' => ['Unable to save the article.']]);

            return;
        }

        $this->saved = true;
        $this->dispatch('article-saved', ['id' => $this->article->get('id')]);
    }

    /**
     * Supply the current entity, including validation errors, to the native form.
     *
     * @return array{article: EntityInterface}
     */
    public function viewData(): array
    {
        return ['article' => $this->article ?? $this->fetchTable('Articles')->newEmptyEntity()];
    }
}
