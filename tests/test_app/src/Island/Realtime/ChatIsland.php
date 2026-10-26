<?php
declare(strict_types=1);

namespace TestApp\Island\Realtime;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\View\View;
use Exception;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\PublisherInterface;
use Interact\Realtime\Subscription;
use TestApp\Chat\ChatIdentity;
use TestApp\Model\Table\ChatMessagesTable;

/**
 * Owns the shared composer, explicit message persistence, and scripted assistant requests.
 *
 * The independent feed receives shared messages without interrupting this draft.
 * A successful send refreshes its local feed even when live delivery is unavailable.
 */
class ChatIsland extends Island
{
    use LocatorAwareTrait;

    #[State(writable: true)]
    public string $name = 'Guest';

    #[State(writable: true)]
    public string $message = '';

    #[State(writable: true)]
    public bool $private = false;

    #[State]
    public bool $deliveryIssue = false;

    protected ChatMessagesTable $messages;

    protected ChatIdentity $identity;

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
        $this->identity = ChatIdentity::authorize($context);
    }

    /**
     * Give a new visitor an immediately usable, editable display name.
     */
    public function mount(): void
    {
        $this->name = 'Guest ' . strtoupper(bin2hex(random_bytes(2)));
    }

    /**
     * Receive private message events without interrupting the composer draft.
     *
     * @return list<Subscription> Topics selected from the freshly authorized identity.
     */
    public function subscriptions(IslandContext $context): array
    {
        if (!$this->publisher instanceof PublisherInterface) {
            return [];
        }

        return [Subscription::listen($this->identity->topic(), event: 'message-sent')];
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
            'recipient' => $this->private ? $this->identity->recipient : null,
        ], ['fields' => ['name', 'message', 'recipient']]);
        if (!$this->messages->save($message)) {
            $this->setErrors($message->getErrors() ?: ['message' => ['Unable to save the message. Try again.']]);

            return;
        }

        $this->message = '';
        $this->deliveryIssue = false;
        $this->dispatch('chat-sent', id: $message->get('id'));
        try {
            $this->publisher?->publishEvent($this->private ? $this->identity->topic() : 'demo/chat', 'message-sent', [
                'id' => $message->get('id'),
            ]);
        } catch (Exception) {
            // The message is already saved. Never invite a duplicate send because delivery failed.
            $this->deliveryIssue = true;
        }
    }

    /**
     * Stream a scripted assistant reply to this session's tabs using text, events, and HTML.
     */
    #[Action(render: false)]
    public function reply(): void
    {
        $topic = $this->identity->topic() . '/reply';
        $chunks = [
            'Hello! I’m the demo assistant. ',
            'This reply arrives in pieces, while the server is still working. ',
            'Your other tabs receive the same text and progress. ',
            'No AI service is involved — just a scripted reply over your shared connection.',
        ];
        $this->publisher?->publishEvent($topic, 'reply-progress', ['percent' => 0]);
        foreach ($chunks as $index => $chunk) {
            $this->publisher?->publishText($topic, 'reply-text', 'chat-reply', $chunk, ['replace' => $index === 0]);
            usleep(200_000);
            $this->publisher?->publishEvent($topic, 'reply-progress', ['percent' => ($index + 1) * 25]);
        }

        $html = new View()->element('Realtime/reply_status', [
            'count' => $this->messages->find('recent', recipient: $this->identity->recipient)->all()->count(),
        ]);
        $this->publisher?->publishHtml($topic, 'reply-status', 'chat-reply-status', $html, ['replace' => true]);
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
