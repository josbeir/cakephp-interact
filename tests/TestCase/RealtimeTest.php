<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\Exception\ServiceUnavailableException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Utility\Hash;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Interact\Discovery\Registry;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\BroadcasterInterface;
use Interact\Realtime\Grant;
use Interact\Realtime\LeaseCodec;
use Interact\Realtime\LeaseIdentity;
use Interact\Realtime\MercureProvider;
use Interact\Realtime\Message;
use Interact\Realtime\Publisher;
use Interact\Realtime\Subscription;
use Interact\Realtime\SubscriptionAuthorizerInterface;
use Interact\Realtime\SubscriptionHandler;
use Interact\Realtime\Subscriptions;
use Interact\Runtime\Runtime;
use Interact\Runtime\Values;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CallbackRenderer;
use Interact\Test\Fixture\RealtimeFixtureIsland;
use InvalidArgumentException;
use LengthException;
use LogicException;
use Mercure\Jwt\FirebaseTokenFactory;
use Mercure\Service\PublisherInterface;
use Mercure\TestSuite\MockPublisher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Verifies public realtime contracts and the separation of subscription and action authority.
 *
 * Real class resolution, state restoration, signing, and JWT generation exercise
 * security boundaries without requiring a network hub in PHP unit tests.
 */
class RealtimeTest extends TestCase
{
    protected Registry $registry;

    protected LeaseCodec $leases;

    protected IslandContext $context;

    protected Runtime $runtime;

    protected Subscriptions $subscriptions;

    /**
     * Assemble a real runtime using request-local sessions and subscription-only signing.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new Registry();
        $this->registry->register(RealtimeFixtureIsland::class);

        $this->leases = new LeaseCodec(str_repeat('k', 32));
        $this->subscriptions = new Subscriptions($this->leases);
        $this->context = new IslandContext(
            new ServerRequest(['session' => new Session()]),
            'default',
            '/update',
            '/subscribe',
        );
        $this->runtime = new Runtime(
            $this->registry,
            new SnapshotCodec(str_repeat('k', 32)),
            new CallbackRenderer(static fn(): string => '<p>Current data</p>'),
        );
        $this->runtime->attachSubscriptions($this->subscriptions);

        RealtimeFixtureIsland::$trace = [];
    }

    public function testDeclarationsAndMessagesPreserveTheExistingDisplayVocabulary(): void
    {
        static::assertSame('refresh', Subscription::refresh('records/1')->mode);
        static::assertSame('event', Subscription::listen('users/1', 'notice', false)->mode);
        static::assertFalse(Subscription::listen('users/1', private: false)->data()['private']);
        static::assertSame('stream', Subscription::stream('jobs/1')->mode);
        $message = new Message(
            'changed',
            ['null' => null, 'false' => false, 'zero' => 0, 'empty' => ''],
            id: 'stable-id',
        );
        $data = Values::map(json_decode($message->encode('records/1'), true, flags: JSON_THROW_ON_ERROR));
        static::assertSame('stable-id', $data['id']);
        static::assertSame($message->detail, $data['detail']);
        static::assertSame('records/1', $data['topic']);
        static::assertFalse(Message::text('output', 'answer', '<b>text</b>')->display['html'] ?? null);
        static::assertTrue(Message::html('output', 'answer', '<b>safe</b>', true)->display['replace'] ?? null);
    }

    /**
     * Supply invalid public declarations and envelope data.
     *
     * @return iterable<string, array{string, string}> Invalid declaration kind/value pairs.
     */
    public static function invalidDeclarations(): iterable
    {
        foreach (['', '*', "has\nline", 'has space', str_repeat('x', 2049)] as $index => $topic) {
            yield 'topic-' . $index => ['topic', $topic];
        }

        yield 'mode' => ['mode', 'unknown'];
        yield 'event' => ['event', 'Invalid'];
        yield 'identity' => ['identity', 'with space'];
    }

    /**
     * Reject invalid values before provider I/O or browser delivery.
     */
    #[DataProvider('invalidDeclarations')]
    public function testInvalidDeclarations(string $kind, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        match ($kind) {
            'topic' => Subscription::refresh($value),
            'mode' => new Subscription('topic', $value),
            'event' => Subscription::listen('topic', $value),
            default => new Message('event', id: $value),
        };
    }

    public function testOversizedMessagesFailBeforePublishing(): void
    {
        $this->expectException(LengthException::class);
        Message::text('output', 'answer', str_repeat('a', 1_048_576))->encode('topic');
    }

    public function testMountAndRenderlessResultsExportSubscriptionMetadata(): void
    {
        $mount = $this->runtime->mount('RealtimeFixture', ['recordId' => 7], 'first', $this->context);
        static::assertNotNull($mount->realtime);
        static::assertSame(
            ['initialize', 'authorize', 'mount', 'viewData', 'subscriptions'],
            RealtimeFixtureIsland::$trace,
        );
        static::assertStringContainsString('data-interact-realtime=', $mount->html);
        static::assertSame('/subscribe', $mount->realtime['endpoint']);
        static::assertSame('records/7', $mount->realtime['subscriptions'][0]['topic']);
        static::assertSame($mount->realtime, $mount->payload()['realtime']);
        $result = $this->runtime->update(['snapshot' => $mount->snapshot, 'action' => 'unsubscribe'], $this->context);
        static::assertNull($result->payload()['html']);
        static::assertNull($result->realtime);
    }

    public function testRenewalReauthorizesWithoutApplicationBehaviorAndCannotChangeTopics(): void
    {
        $mount = $this->runtime->mount('RealtimeFixture', ['recordId' => 7], 'first', $this->context);
        static::assertNotNull($mount->realtime);
        $provider = $this->createMock(SubscriptionAuthorizerInterface::class);
        $provider->expects($this->once())
            ->method('authorize')
            ->with(['records/7'], static::callback(static fn(int $expires): bool => $expires > time()))
            ->willReturn(new Grant('test', ['url' => '/feed'], time() + 300));
        RealtimeFixtureIsland::$trace = [];
        $body = $this->authorize($provider, [$mount->id => $mount->realtime['lease']]);
        static::assertSame(['initialize', 'authorize', 'subscriptions'], RealtimeFixtureIsland::$trace);
        static::assertSame('test', Hash::get($body, 'grant.driver'));
        static::assertSame('records/7', Hash::get($body, 'islands.' . $mount->id . '.subscriptions.0.topic'));
        static::assertArrayNotHasKey('snapshot', Values::map(Hash::get($body, 'islands.' . $mount->id)));
    }

    public function testUnauthorizedOrForgedMembersDoNotRemoveOtherAuthorizedTopics(): void
    {
        $mount = $this->runtime->mount('RealtimeFixture', ['recordId' => 7], 'first', $this->context);
        static::assertNotNull($mount->realtime);
        $provider = $this->createMock(SubscriptionAuthorizerInterface::class);
        $provider->expects($this->once())
            ->method('authorize')
            ->willReturn(new Grant('test', [], time() + 300));
        $body = $this->authorize($provider, [
            $mount->id => $mount->realtime['lease'],
            'forged' => $mount->snapshot,
            'bad' => 12,
        ]);
        static::assertArrayHasKey('lease', Values::map(Hash::get($body, 'islands.' . $mount->id)));
        static::assertArrayHasKey('error', Values::map(Hash::get($body, 'islands.forged')));
        static::assertArrayHasKey('error', Values::map(Hash::get($body, 'islands.bad')));
        $denied = $this->authorize($provider, [$mount->id => $mount->realtime['lease']], false);
        static::assertNull($denied['grant']);
        static::assertSame(
            ['error' => 'Subscription authorization failed.'],
            Hash::get($denied, 'islands.' . $mount->id),
        );
        $mismatch = $this->authorize($provider, ['other' => $mount->realtime['lease']]);
        static::assertArrayHasKey('error', Values::map(Hash::get($mismatch, 'islands.other')));
    }

    public function testSubscriptionLeasesCannotAuthorizeOrdinaryActions(): void
    {
        $mount = $this->runtime->mount('RealtimeFixture', ['recordId' => 7], 'first', $this->context);
        static::assertNotNull($mount->realtime);
        $this->expectException(InvalidSnapshotException::class);
        $this->runtime->update(['snapshot' => $mount->realtime['lease'], 'action' => 'unsubscribe'], $this->context);
    }

    public function testLeaseExpiryUsesAnIndependentClockAndLifetime(): void
    {
        $now = 1000;
        $codec = new LeaseCodec(str_repeat('k', 32), clock: static function () use (&$now): int {
            return $now;
        });
        static::assertSame(1300, $codec->expires());
        $token = $codec->seal(['id' => 'a'], 'session', 'scope');
        static::assertSame(['id' => 'a'], $codec->open($token, 'session', 'scope'));
        $now = 1300;
        $this->expectException(InvalidSnapshotException::class);
        $codec->open($token, 'session', 'scope');
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}> Invalid declaration collections.
     */
    public static function invalidSubscriptionLists(): iterable
    {
        yield 'keyed' => [['article' => Subscription::refresh('records/1')]];
        yield 'too many' => [array_fill(0, 129, Subscription::refresh('records/1'))];
        yield 'wrong entry' => [['not a declaration']];
    }

    #[DataProvider('invalidSubscriptionLists')]
    public function testRejectsInvalidSubscriptionCollections(array $declarations): void
    {
        $island = $this->createMock(Island::class);
        $island->expects($this->once())->method('subscriptions')->willReturn($declarations);
        $this->expectException(InvalidArgumentException::class);
        $this->subscriptions->declarations($island, $this->context);
    }

    public function testAuthorizationInfrastructureFailuresAreNotReportedAsPermissionDenials(): void
    {
        $mount = $this->runtime->mount('RealtimeFixture', ['recordId' => 7], 'first', $this->context);
        static::assertNotNull($mount->realtime);
        $definition = $this->registry->definition('RealtimeFixture');
        $island = $this->createMock(RealtimeFixtureIsland::class);
        $island->expects($this->once())
            ->method('authorize')
            ->willThrowException(new ServiceUnavailableException('Authorization service unavailable.'));
        $registry = $this->createMock(Registry::class);
        $registry->expects($this->once())->method('definition')->with('RealtimeFixture')->willReturn($definition);
        $registry->expects($this->once())->method('create')->with($definition)->willReturn($island);
        $provider = $this->createMock(SubscriptionAuthorizerInterface::class);
        $provider->expects($this->never())->method('authorize');
        $request = $this->context
            ->request
            ->withMethod('POST')
            ->withParsedBody([
                'version' => 1,
                'leases' => [$mount->id => $mount->realtime['lease']],
            ]);
        $this->expectException(ServiceUnavailableException::class);
        new SubscriptionHandler($registry, $this->leases, $provider, $this->subscriptions)->handle(
            $request,
            new Response(),
        );
    }

    public function testDisabledProviderFailsClearlyForDeclaredSubscriptions(): void
    {
        $this->runtime->attachSubscriptions(new Subscriptions($this->leases, false));
        $this->expectException(LogicException::class);
        $this->runtime->mount('RealtimeFixture', ['recordId' => 1], 'first', $this->context);
    }

    public function testInvalidLeaseIdentityAndGrantAreRejected(): void
    {
        try {
            new Grant('provider', [], 0);
            static::fail('Expected expiry validation.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            static::assertStringContainsString('expiry', $invalidArgumentException->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new LeaseIdentity(['alias' => 'RealtimeFixture', 'id' => '', 'endpoint' => '/update']);
    }

    public function testMercurePublishingAndSubscribeOnlyTokensUseTheRealPlugin(): void
    {
        $publisher = new MockPublisher();
        $secret = str_repeat('k', 32);
        $factory = new FirebaseTokenFactory($secret, defaultClaims: ['iss' => 'issuer', 'aud' => 'audience']);
        $provider = new MercureProvider($publisher, $factory, 'https://hub.example.test/.well-known/mercure');
        $provider->publish('records/1', Message::event('changed', ['id' => 1]));

        $grant = $provider->authorize(['records/1'], time() + 300);
        static::assertSame('mercure', $grant->driver);
        static::assertSame(['records/1'], $grant->data['topics']);
        static::assertIsString($grant->data['token']);
        $claims = Values::map(json_decode(
            json_encode(JWT::decode($grant->data['token'], new Key($secret, 'HS256')), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        static::assertSame(['subscribe'], Hash::get($claims, 'authorization_details.0.actions'));
        static::assertSame('records/1', Hash::get($claims, 'authorization_details.0.topics.0.match'));
        static::assertIsArray($claims['authorization_details']);
        static::assertCount(1, $claims['authorization_details']);
    }

    public function testPublisherShortcutsShareConstructionAcrossProviders(): void
    {
        $broadcaster = $this->createMock(BroadcasterInterface::class);
        $messages = [];
        $broadcaster->expects($this->exactly(4))
            ->method('publish')
            ->willReturnCallback(
                static function (string $topic, Message $message, bool $private) use (&$messages): void {
                    $messages[] = [$topic, $message, $private];
                },
            );
        $publisher = new Publisher($broadcaster);
        $publisher->publishEvent('topic', 'changed', ['id' => 7]);
        $publisher->publishText('topic', 'output', 'answer', '<text>');
        $publisher->publishHtml('topic', 'output', 'rows', '<li>Result</li>', ['replace' => true, 'private' => false]);

        $message = new Message('explicit', id: 'retained');
        $publisher->publish('topic', $message);
        static::assertSame(['id' => 7], $messages[0][1]->detail);
        static::assertFalse($messages[1][1]->display['html'] ?? null);
        static::assertTrue($messages[2][1]->display['replace'] ?? null);
        static::assertFalse($messages[2][2]);
        static::assertSame($message, $messages[3][1]);
    }

    public function testPublisherRejectsMisspelledDisplayOptions(): void
    {
        $publisher = new Publisher($this->createStub(BroadcasterInterface::class));
        $this->expectException(InvalidArgumentException::class);
        new ReflectionMethod($publisher, 'publishText')->invoke($publisher, 'topic', 'output', 'answer', 'text', [
            'unknown' => true,
        ]);
    }

    public function testProviderPublicationFailureIsNotRetried(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')->willReturn(false);
        $provider = new MercureProvider($publisher, new FirebaseTokenFactory(str_repeat('k', 32)), '/hub');
        $this->expectException(RuntimeException::class);
        $provider->publish('records/1', Message::event('changed'));
    }

    /**
     * Submit subscription-only tokens through the real request handler.
     *
     * @param array<string, mixed> $tokens Untrusted browser lease records.
     * @return array<string, mixed> Decoded subscription response.
     */
    protected function authorize(SubscriptionAuthorizerInterface $provider, array $tokens, bool $allowed = true): array
    {
        $request = $this->context
            ->request
            ->withMethod('POST')
            ->withAttribute('realtimeAllowed', $allowed)
            ->withParsedBody(['version' => 1, 'leases' => $tokens]);
        $response = new SubscriptionHandler($this->registry, $this->leases, $provider, $this->subscriptions)->handle(
            $request,
            new Response(),
        );
        static::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        return Values::map(json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }
}
