<?php
declare(strict_types=1);

namespace App\Island;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Creates an article with normal model validation and navigates to its detail page.
 *
 * Save as src/Island/ArticleRedirectIsland.php.
 */
class ArticleRedirectIsland extends Island
{
    use LocatorAwareTrait;

    #[State(writable: true)]
    public string $title = '';

    protected ?EntityInterface $article = null;

    /**
     * Keep failed entity errors on the form, or redirect after saving succeeds.
     */
    #[Action]
    public function save(): void
    {
        $articles = $this->fetchTable('Articles');
        $this->article = $articles->newEntity(['title' => $this->title]);
        if (!$articles->save($this->article)) {
            $this->setErrors($this->article->getErrors() ?: ['_form' => ['Unable to save the article.']]);

            return;
        }

        $this->redirect(['_name' => 'articles:view', 'id' => $this->article->get('id')]);
    }

    /**
     * Preserve the entity's validation context without transporting it to the browser.
     *
     * @return array{article: EntityInterface} Request-local form entity.
     */
    public function viewData(): array
    {
        return ['article' => $this->article ?? $this->fetchTable('Articles')->newEmptyEntity()];
    }
}
