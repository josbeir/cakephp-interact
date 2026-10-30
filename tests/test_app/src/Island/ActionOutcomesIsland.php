<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Datasource\EntityInterface;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use TestApp\Model\Table\OutcomeArticlesTable;

/**
 * Demonstrates native model validation followed by navigation or a separate invoice download.
 * The download endpoint independently checks access rather than trusting the island response.
 */
class ActionOutcomesIsland extends Island
{
    protected OutcomeArticlesTable $articles;

    #[State(writable: true)]
    public string $title = '';

    #[State]
    public int $exports = 0;

    protected ?EntityInterface $article = null;

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->articles = $this->fetchTable(OutcomeArticlesTable::class);
    }

    /**
     * Validate and create a demo article before navigating to its application page.
     */
    #[Action]
    public function save(bool $replace = false): void
    {
        $articles = $this->articles;
        $this->article = $articles->newEntity(['title' => $this->title]);
        if (!$articles->save($this->article)) {
            $this->setErrors($this->article->getErrors() ?: ['_form' => ['Unable to save the article.']]);

            return;
        }

        $this->redirect(['action' => 'saved', '?' => ['id' => $this->article->get('id')]], replace: $replace);
    }

    /**
     * Update the rendered export count and request a native, independently authorized download.
     */
    #[Action]
    public function download(): void
    {
        $this->exports++;
        $this->downloadUrl(['action' => 'invoice']);
    }

    /**
     * Demonstrate an intentionally external redirect through the same API.
     */
    #[Action]
    public function external(): void
    {
        $this->redirect('https://example.com/');
    }

    /**
     * Exercise a same-page fragment redirect as full navigation with a fresh island.
     */
    #[Action]
    public function fragment(): void
    {
        $this->redirect(['action' => 'index', '#' => 'main']);
    }

    /**
     * Keep the same failed entity available to FormHelper on a validation render.
     *
     * @return array{article: EntityInterface} Server-only form context.
     */
    public function viewData(): array
    {
        return ['article' => $this->article ?? $this->articles->newEmptyEntity()];
    }
}
