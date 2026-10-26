<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Events;

use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;

/**
 * Exercises request-local listener scopes without caching a mounted record identity.
 * Reactive props remain parent-owned even when a listener or renderless action runs.
 */
#[Render(element: 'events')]
class ScopedListenerIsland extends ListenerIsland
{
    #[Prop(reactive: true)]
    public int $articleId = 1;

    #[Prop]
    public string $scope = 'public';

    /** @var list<string> A structured prop cannot participate in a listener name. */
    #[Prop]
    public array $unsupported = [];

    #[Prop]
    public bool $enabled = true;

    #[State]
    public string $stateKey = 'not-a-prop';

    public string $undeclared = 'not-transported';

    /**
     * Confirm that concrete event routing does not require a matching payload parameter.
     */
    #[Action(on: 'article-saved:{articleId}')]
    public function articleSaved(): void
    {
        $this->received = ['articleId' => $this->articleId];
    }

    /**
     * Retain literal and dynamic listeners that resolve to the same concrete name.
     */
    #[Action(on: 'article-saved:1')]
    public function firstArticle(): void
    {
        $this->received = ['first' => true];
    }

    /**
     * A renderless application action can replace an accepted scope.
     */
    #[Action(render: false)]
    public function selectArticle(int $id): void
    {
        $this->articleId = $id;
    }
}
