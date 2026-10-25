<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\View\View;
use Exception;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\PublisherInterface;
use Interact\Realtime\Subscription;
use TestApp\Model\Table\ChatMessagesTable;

/**
 * Owns a message draft, explicit persistence, and the demo's private notification tools.
 *
 * The independent feed receives shared messages without interrupting this draft.
 * A successful send refreshes its local feed even when live delivery is unavailable.
 */
class RealtimeChatIsland extends Island
{
    use LocatorAwareTrait;

    #[State(writable: true)]
    public string $name = 'Guest';

    #[State(writable: true)]
    public string $message = '';

    #[State]
    public bool $deliveryIssue = false;

    protected ChatMessagesTable $messages;

    protected int $userId;

    /**
     * Allow the ordinary preview to send messages without optional hub infrastructure.
     */
    public function __construct(protected readonly ?PublisherInterface $publisher = null)
    {
    }

    /**
     * Resolve the application's Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->messages = $this->fetchTable(ChatMessagesTable::class);
    }

    /**
     * Derive private topics from the current identity on every request and lease renewal.
     */
    public function authorize(IslandContext $context): void
    {
        /** @var mixed $identity */
        $identity = $context->request->getAttribute('identity');
        if (!is_array($identity) || ($identity['role'] ?? null) !== 'editor' || !is_int($identity['id'] ?? null)) {
            throw new ForbiddenException('Chat access is not allowed.');
        }

        $this->userId = $identity['id'];
    }

    /**
     * Give a new visitor an immediately usable, editable display name.
     */
    public function mount(): void
    {
        $this->name = 'Guest ' . strtoupper(bin2hex(random_bytes(2)));
    }

    /**
     * Receive private notifications and streamed output independently of the public chat feed.
     *
     * @return list<Subscription> Topics selected from the freshly authorized identity.
     */
    public function subscriptions(IslandContext $context): array
    {
        if (!$this->publisher instanceof PublisherInterface) {
            return [];
        }

        return [
            Subscription::listen('users/' . $this->userId . '/notifications'),
            Subscription::stream('users/' . $this->userId . '/output'),
            Subscription::listen('users/' . $this->userId . '/output', event: 'report-progress'),
        ];
    }

    /**
     * Validate and save once, then signal readers to fetch the persisted conversation.
     */
    #[Action(render: false)]
    public function send(): void
    {
        $message = $this->messages->newEntity([
            'name' => trim($this->name),
            'message' => trim($this->message),
        ], ['fields' => ['name', 'message']]);
        if (!$this->messages->save($message)) {
            $this->setErrors($message->getErrors() ?: ['message' => ['Unable to save the message. Try again.']]);

            return;
        }

        $this->message = '';
        $this->deliveryIssue = false;
        $this->dispatch('chat-sent', ['id' => $message->get('id')]);
        try {
            $this->publisher?->publishEvent('demo/chat', 'message-sent', ['id' => $message->get('id')]);
        } catch (Exception) {
            // The message is already saved. Never invite a duplicate send because delivery failed.
            $this->deliveryIssue = true;
        }
    }

    /**
     * Notify this user's other tabs without rendering a view.
     */
    #[Action(render: false)]
    public function notify(): void
    {
        $this->publisher?->publishEvent('users/' . $this->userId . '/notifications', 'notification', [
            'text' => 'An update just arrived.',
        ]);
    }

    /**
     * Send escaped text and a rendered conversation recap through shared hub delivery.
     */
    #[Action(render: false)]
    public function output(): void
    {
        $topic = 'users/' . $this->userId . '/output';
        $steps = ['Messages loaded.', 'Access checked.', 'Recap assembled.', 'Report ready.'];
        foreach ($steps as $index => $step) {
            $this->publisher?->publishText($topic, 'report-output', 'realtime-output', $step . "\n", [
                'replace' => $index === 0,
            ]);
            $this->publisher?->publishEvent($topic, 'report-progress', ['percent' => ($index + 1) * 25]);
            usleep(120_000);
        }

        $html = new View()->element('Realtime/report_result', [
            'count' => $this->messages->find('recent')->all()->count(),
        ]);
        $this->publisher?->publishHtml($topic, 'report-output', 'realtime-rows', $html, ['replace' => true]);
    }

    /**
     * Keep infrastructure availability server-owned rather than writable browser state.
     *
     * @return array{live: bool} Whether to show the optional realtime tools.
     */
    public function viewData(): array
    {
        return ['live' => $this->publisher instanceof PublisherInterface];
    }
}
