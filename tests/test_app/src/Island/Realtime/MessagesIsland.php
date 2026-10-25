<?php
declare(strict_types=1);

namespace TestApp\Island\Realtime;

use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Prop;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\Subscription;
use TestApp\Chat\ChatIdentity;
use TestApp\Model\Table\ChatMessagesTable;

/**
 * Renders saved chat history independently of the composer's writable draft.
 *
 * Refreshes read the database, so reconnecting or reloading also recovers missed messages.
 * Public messages are shared; private messages remain scoped to the current session.
 */
class MessagesIsland extends Island
{
    use LocatorAwareTrait;

    #[Prop]
    public bool $live = false;

    protected ChatIdentity $identity;

    /**
     * Recheck demo access before reading messages or issuing a subscription lease.
     */
    public function authorize(IslandContext $context): void
    {
        $this->identity = ChatIdentity::authorize($context);
    }

    /**
     * Invalidate this read-only feed while other islands retain their local drafts.
     *
     * @return list<Subscription> A shared room topic, without any user-private data.
     */
    public function subscriptions(IslandContext $context): array
    {
        return (
            $this->live
                ? [
                    Subscription::refresh('demo/chat', event: 'message-sent'),
                    Subscription::refresh($this->identity->topic(), event: 'message-sent'),
                ] : []
        );
    }

    /**
     * Supply bounded persistent history only to the template, never to a signed snapshot.
     *
     * @return array{messages: \Cake\Datasource\ResultSetInterface<array-key, \Cake\Datasource\EntityInterface>} Messages in reading order.
     */
    public function viewData(): array
    {
        $messages = $this->fetchTable(ChatMessagesTable::class);

        return ['messages' => $messages->find('recent', recipient: $this->identity->recipient)->all()];
    }
}
