<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Container\Container;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Interact\Configuration;
use Interact\Realtime\BroadcasterInterface;
use Interact\Realtime\Message;
use Interact\Realtime\PublisherInterface;
use Interact\Realtime\RealtimeServices;
use Interact\Realtime\SubscriptionAuthorizerInterface;
use Interact\Test\Fixture\WebSocketProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises the documented custom provider against controlled HTTP gateway responses.
 *
 * Tests retain real message encoding and container integration, verifying that
 * application publishing and subscription grants work without the Mercure adapter.
 */
class WebSocketProviderExampleTest extends TestCase
{
    public function testGrantRequestsExactPermissionsAndKeepsManagementCredentialsInPhp(): void
    {
        $http = $this->createMock(Client::class);
        $expires = time() + 300;
        $subscriber = bin2hex(random_bytes(16));
        $http->expects($this->once())
            ->method('post')
            ->with(
                'https://gateway.example.test/api/subscriptions',
                json_encode(['topics' => ['articles/1'], 'expires' => $expires], JSON_THROW_ON_ERROR),
                [
                    'type' => 'json',
                    'headers' => ['Authorization' => 'Bearer management-only'],
                    'redirect' => false,
                    'timeout' => 5,
                ],
            )
            ->willReturn(new Response(['HTTP/1.1 200 OK'], json_encode(['token' => $subscriber], JSON_THROW_ON_ERROR)));
        $provider = new WebSocketProvider(
            $http,
            'https://gateway.example.test/api/',
            'wss://gateway.example.test/socket',
            'management-only',
        );
        $grant = $provider->authorize(['articles/1'], $expires);
        static::assertSame(
            [
                'driver' => 'websocket',
                'data' => [
                    'url' => 'wss://gateway.example.test/socket',
                    'token' => $subscriber,
                    'topics' => ['articles/1'],
                ],
                'expires' => $expires,
            ],
            $grant->data(),
        );
        static::assertStringNotContainsString('management-only', json_encode($grant->data(), JSON_THROW_ON_ERROR));
    }

    public function testContainerUsesCustomProviderForApplicationPublishing(): void
    {
        $http = $this->createMock(Client::class);
        $message = Message::event('article-updated', ['id' => 1]);
        $http->expects($this->once())
            ->method('post')
            ->with(
                'https://gateway.example.test/api/publish',
                json_encode([
                    'private' => true,
                    'message' => json_decode($message->encode('articles/1'), flags: JSON_THROW_ON_ERROR),
                ], JSON_THROW_ON_ERROR),
                $this->isArray(),
            )
            ->willReturn(new Response(['HTTP/1.1 202 Accepted']));
        $provider = new WebSocketProvider(
            $http,
            'https://gateway.example.test/api',
            'wss://gateway.example.test/socket',
            'management-only',
        );
        $container = new Container();
        $container->addShared(
            Configuration::class,
            static fn(): Configuration => new Configuration([
                'snapshots' => ['key' => str_repeat('k', 32)],
            ]),
        );
        foreach ([BroadcasterInterface::class, SubscriptionAuthorizerInterface::class] as $contract) {
            $container->addShared($contract, static fn(): WebSocketProvider => $provider);
        }

        $previous = Configure::read('Interact.realtime');
        Configure::delete('Interact.realtime');
        try {
            new RealtimeServices()->register($container);
            $publisher = $container->get(PublisherInterface::class);
            static::assertInstanceOf(PublisherInterface::class, $publisher);
            static::assertSame($provider, $container->get(SubscriptionAuthorizerInterface::class));
            $publisher->publish('articles/1', $message);
        } finally {
            Configure::write('Interact.realtime', $previous);
        }
    }

    /**
     * @return iterable<string, array{string}> Invalid gateway responses cannot become browser grants.
     */
    public static function invalidTokens(): iterable
    {
        yield 'invalid JSON' => ['not JSON'];
        yield 'null' => ['null'];
        yield 'missing token' => ['{}'];
        yield 'empty token' => ['{"token":""}'];
        yield 'wrong type' => ['{"token":42}'];
    }

    #[DataProvider('invalidTokens')]
    public function testRejectsInvalidGatewayTokens(string $body): void
    {
        $http = $this->createMock(Client::class);
        $http->expects($this->once())
            ->method('post')
            ->willReturn(new Response(['HTTP/1.1 200 OK'], $body));
        $provider = new WebSocketProvider(
            $http,
            'https://gateway.example.test/api',
            'wss://gateway.example.test/socket',
            'management-only',
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid subscription token');
        $provider->authorize(['articles/1'], time() + 300);
    }

    public function testEmptyNotificationDetailRemainsAJsonRecord(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects($this->once())
            ->method('post')
            ->with(
                'https://gateway.example.test/api/publish',
                '{"private":false,"message":{"version":1,"id":"stable","topic":"catalog","event":"notice","detail":{}}}',
                $this->isArray(),
            )
            ->willReturn(new Response(['HTTP/1.1 200 OK']));
        $provider = new WebSocketProvider(
            $http,
            'https://gateway.example.test/api',
            'wss://gateway.example.test/socket',
            'management-only',
        );
        $provider->publish('catalog', new Message('notice', id: 'stable'), private: false);
    }

    public function testFailedPublicPublicationThrowsWithoutRetrying(): void
    {
        $http = $this->createMock(Client::class);
        $message = Message::text('report-output', 'output', 'Next result');
        $http->expects($this->once())
            ->method('post')
            ->with(
                'https://gateway.example.test/api/publish',
                json_encode([
                    'private' => false,
                    'message' => json_decode($message->encode('reports/1'), flags: JSON_THROW_ON_ERROR),
                ], JSON_THROW_ON_ERROR),
                $this->isArray(),
            )
            ->willReturn(new Response(['HTTP/1.1 503 Service Unavailable']));
        $provider = new WebSocketProvider(
            $http,
            'https://gateway.example.test/api',
            'wss://gateway.example.test/socket',
            'management-only',
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gateway request failed');
        $provider->publish('reports/1', $message, private: false);
    }
}
