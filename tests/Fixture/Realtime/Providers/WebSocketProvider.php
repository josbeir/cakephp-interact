<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Realtime\Providers;

use Cake\Http\Client;
use Cake\Http\Client\Response;
use Interact\Realtime\BroadcasterInterface;
use Interact\Realtime\Grant;
use Interact\Realtime\Message;
use Interact\Realtime\SubscriptionAuthorizerInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * Bridges an application-owned WebSocket gateway to Interact's provider contracts.
 *
 * The gateway's HTTP API owns subscription tokens and socket delivery. Only its
 * subscriber token reaches the browser; its management API key stays in PHP.
 */
class WebSocketProvider implements BroadcasterInterface, SubscriptionAuthorizerInterface
{
    /**
     * Inject CakePHP HTTP behavior and application-supplied gateway configuration.
     */
    public function __construct(
        protected readonly Client $http,
        protected readonly string $apiUrl,
        protected readonly string $socketUrl,
        #[SensitiveParameter]
        protected readonly string $apiKey,
    ) {
    }

    /**
     * Forward the normalized envelope and delivery privacy through the management API.
     */
    public function publish(string $topic, Message $message, bool $private = true): void
    {
        $this->post('/publish', [
            'private' => $private,
            'message' => json_decode($message->encode($topic), flags: JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Request an expiring subscribe-only token for the already authorized topic set.
     *
     * @param list<string> $topics Exact topics selected by authorized island declarations.
     */
    public function authorize(array $topics, int $expires): Grant
    {
        $response = $this->post('/subscriptions', ['topics' => $topics, 'expires' => $expires]);
        /** @var mixed $data */
        $data = $response->getJson();
        if (!is_array($data) || !is_string($data['token'] ?? null) || $data['token'] === '') {
            throw new RuntimeException('The WebSocket gateway returned an invalid subscription token.');
        }

        return new Grant(
            'websocket',
            [
                'url' => $this->socketUrl,
                'token' => $data['token'],
                'topics' => $topics,
            ],
            $expires,
        );
    }

    /**
     * Make one authenticated management request without redirects or publication retries.
     *
     * @param array<string, mixed> $data Gateway request data, never browser-chosen permissions.
     */
    protected function post(string $path, array $data): Response
    {
        $response = $this->http->post(
            rtrim($this->apiUrl, '/') . $path,
            json_encode($data, JSON_THROW_ON_ERROR),
            [
                'type' => 'json',
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'redirect' => false,
                'timeout' => 5,
            ],
        );
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('The WebSocket gateway request failed.');
        }

        return $response;
    }
}
