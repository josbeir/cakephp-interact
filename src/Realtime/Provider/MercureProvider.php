<?php
declare(strict_types=1);

namespace Interact\Realtime\Provider;

use Interact\Realtime\BroadcasterInterface;
use Interact\Realtime\Grant;
use Interact\Realtime\Message;
use Interact\Realtime\Subscription;
use Interact\Realtime\SubscriptionAuthorizerInterface;
use Mercure\Jwt\TokenFactoryInterface;
use Mercure\Service\PublisherInterface as MercurePublisher;
use Mercure\Update\Update;
use RuntimeException;

/**
 * Adapts the optional CakePHP Mercure plugin to Interact's publishing and grant contracts.
 *
 * Browser grants carry exact subscribe permissions only. Publishing remains an HTTP
 * operation so controllers, commands, and queue consumers use the same behavior.
 */
class MercureProvider implements BroadcasterInterface, SubscriptionAuthorizerInterface
{
    /**
     * Receive provider behavior and browser configuration from the composition boundary.
     */
    public function __construct(
        protected readonly MercurePublisher $publisher,
        protected readonly TokenFactoryInterface $tokens,
        protected readonly string $publicUrl,
    ) {
    }

    /**
     * Publish a normalized envelope without automatically retrying an uncertain delivery.
     */
    public function publish(string $topic, Message $message, bool $private = true): void
    {
        $update = new Update($topic, $message->encode($topic), $private, $message->id);
        if (!$this->publisher->publish($update)) {
            throw new RuntimeException('Realtime publication failed.');
        }
    }

    /**
     * Issue a tab-local access token without changing application authorization cookies.
     *
     * @param list<string> $topics Exact topics authorized by the application's current identity.
     */
    public function authorize(array $topics, int $expires): Grant
    {
        foreach ($topics as $topic) {
            Subscription::validateTopic($topic);
        }

        return new Grant(
            'mercure',
            [
                'url' => $this->publicUrl,
                'topics' => $topics,
                'token' => $this->tokens->create($topics, [], ['exp' => $expires]),
            ],
            $expires,
        );
    }
}
