<?php
declare(strict_types=1);

namespace TestApp\Island\Realtime;

use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\Subscription;
use TestApp\Chat\ChatIdentity;

/**
 * Owns provisional assistant output independently of history refreshes and composer validation.
 *
 * Its stable child identity preserves streamed DOM while other parts of the chat render.
 * Reply events and text are authorized for the current session on every lease renewal.
 */
class ReplyIsland extends Island
{
    protected ChatIdentity $identity;

    /**
     * Resolve current private access before exporting display subscriptions.
     */
    public function authorize(IslandContext $context): void
    {
        $this->identity = ChatIdentity::authorize($context);
    }

    /**
     * Receive assistant text, native HTML, and progress through one private channel.
     *
     * @return list<Subscription> Display and event delivery selected by the server.
     */
    public function subscriptions(IslandContext $context): array
    {
        return [
            Subscription::stream($this->identity->topic() . '/reply'),
            Subscription::listen($this->identity->topic() . '/reply', event: 'reply-progress'),
        ];
    }
}
