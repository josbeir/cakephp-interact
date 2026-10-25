<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\View\View;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\IslandContext;
use Interact\Realtime\PublisherInterface;
use Interact\Realtime\Subscription;
use TestApp\Island\Article\EditorIsland;

/**
 * Publishes persisted article changes and receives authorized updates across independent tabs.
 *
 * The inherited entity form owns its draft and validation. Computed persisted content
 * refreshes independently, and notification/output topics remain scoped to the current user.
 */
class RealtimeArticleIsland extends EditorIsland
{
    #[Prop]
    public string $editorKey = 'editor';

    /**
     * Inject application publishing behavior; Tables retain CakePHP's locator workflow.
     */
    public function __construct(protected readonly PublisherInterface $publisher)
    {
    }

    /**
     * Receive article changes and user-specific notifications and display output.
     *
     * @return list<Subscription> Topics selected only after inherited resource authorization.
     */
    public function subscriptions(IslandContext $context): array
    {
        if ($this->editorKey === 'monitor') {
            return [Subscription::refresh('articles/' . $this->articleId, event: 'article-updated')];
        }

        $user = $this->articleId === 99 ? 2 : 1;

        return [
            Subscription::refresh('articles/' . $this->articleId, event: 'article-updated'),
            Subscription::listen('users/' . $user . '/notifications'),
            Subscription::stream('users/' . $user . '/output'),
            Subscription::listen('users/' . $user . '/output', event: 'report-progress'),
        ];
    }

    /**
     * Publish only after CakePHP has successfully validated and saved the entity.
     */
    #[Action]
    public function save(): void
    {
        parent::save();
        if ($this->errors() === []) {
            $this->publisher->publishEvent('articles/' . $this->articleId, 'article-updated', [
                'id' => $this->articleId,
            ]);
        }
    }

    /**
     * Notify this user's other tabs without rendering a view.
     */
    #[Action(render: false)]
    public function notify(): void
    {
        $user = $this->articleId === 99 ? 2 : 1;
        $this->publisher->publishEvent('users/' . $user . '/notifications', 'notification', [
            'text' => 'An update just arrived.',
        ]);
    }

    /**
     * Send escaped text and an application-rendered element through shared hub delivery.
     */
    #[Action(render: false)]
    public function output(): void
    {
        $topic = 'users/' . ($this->articleId === 99 ? '2' : '1') . '/output';
        $steps = ['Article loaded.', 'Access checked.', 'Snapshot assembled.', 'Report ready.'];
        foreach ($steps as $index => $step) {
            $this->publisher->publishText($topic, 'report-output', 'realtime-output', $step . "\n", [
                'replace' => $index === 0,
            ]);
            $this->publisher->publishEvent($topic, 'report-progress', ['percent' => ($index + 1) * 25]);
            usleep(120_000);
        }

        $html = new View()->element('Realtime/report_result', [
            'title' => (string)$this->articles->get($this->articleId)->get('title'),
        ]);
        $this->publisher->publishHtml($topic, 'report-output', 'realtime-rows', $html, ['replace' => true]);
    }
}
