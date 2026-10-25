<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Core\Configure;
use Cake\Utility\Hash;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Interact\Runtime\Values;
use Interact\Test\TestSuite\HttpTestCase;
use Mercure\Publisher as MercurePublisher;
use Mercure\TestSuite\MockPublisher;

/**
 * Exercises realtime routes, container services, signed grants, and the demo through CakePHP.
 *
 * The real Mercure plugin handles token generation while a publication boundary
 * double keeps PHP tests independent of a running hub.
 */
class RealtimeHttpTest extends HttpTestCase
{
    /** @var array<string, string|false> Environment restored after each isolated application. */
    protected array $environment = [];

    protected MockPublisher $publisher;

    /**
     * Configure an explicit demonstration provider and a controlled publication boundary.
     */
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'MERCURE_URL' => 'https://hub.example.test/.well-known/mercure',
            'MERCURE_JWT_SECRET' => str_repeat('s', 32),
            'MERCURE_JWT_ISSUER' => 'https://localhost',
            'MERCURE_JWT_AUDIENCE' => 'https://hub.example.test/.well-known/mercure',
        ] as $name => $value) {
            $this->environment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        $this->publisher = new MockPublisher();
        MercurePublisher::setInstance($this->publisher);
    }

    /**
     * Prevent optional provider configuration leaking into ordinary integration suites.
     */
    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        MercurePublisher::clear();
        Configure::delete('Mercure');
        Configure::delete('Interact.realtime');
        parent::tearDown();
    }

    public function testDemoIssuesExactSubscribeOnlyGrantsThroughTheUnifiedController(): void
    {
        $mount = $this->mounted(url: '/realtime');
        $metadata = $this->metadata();
        $this->subscribe([$mount['id'] => $metadata['lease']], ['topics' => ['forbidden/topic']]);
        $this->assertResponseOk();
        $this->assertHeader('Interact', 'true');
        $this->assertHeader('Cache-Control', 'no-store');
        $payload = Values::map(json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR));
        /** @var mixed $token */
        $token = Hash::get($payload, 'grant.data.token');
        static::assertIsString($token);
        $claims = Values::map(json_decode(
            json_encode(JWT::decode($token, new Key(str_repeat('s', 32), 'HS256')), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        static::assertSame(['subscribe'], Hash::get($claims, 'authorization_details.0.actions'));
        static::assertSame('users/1/notifications', Hash::get($claims, 'authorization_details.0.topics.0.match'));
        $this->assertResponseNotContains('forbidden/topic');
        static::assertArrayNotHasKey('snapshot', Values::map(Hash::get($payload, 'islands.' . $mount['id'])));
    }

    public function testCustomScopedSubscriptionEndpointSurvivesSynchronizationAndRenderlessActions(): void
    {
        $mount = $this->mounted(url: '/realtime/scoped');
        $metadata = $this->metadata();
        static::assertSame('/realtime/subscribe', $metadata['endpoint']);
        $snapshot = $mount['snapshot'];
        foreach ([null, 'notify', null] as $action) {
            $this->requestAsJson();
            $this->configRequest(['headers' => ['X-CSRF-Token' => $this->csrf]]);
            $this->post('/realtime/update', json_encode([
                'version' => 1,
                'snapshot' => $snapshot,
                'action' => $action,
                'subscriptionEndpoint' => '/forged/subscribe',
            ], JSON_THROW_ON_ERROR));
            $this->assertResponseOk();
            $payload = Values::map(json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR));
            static::assertSame('/realtime/subscribe', Hash::get($payload, 'realtime.endpoint'));
            if ($action === 'notify') {
                static::assertNull($payload['html']);
            }

            $snapshot = $this->nextSnapshot();
            $metadata = Values::map($payload['realtime']);
        }

        $this->requestAsJson();
        $this->configRequest(['headers' => ['X-CSRF-Token' => $this->csrf]]);
        $this->post('/realtime/subscribe', json_encode([
            'version' => 1,
            'leases' => [$mount['id'] => $metadata['lease']],
        ], JSON_THROW_ON_ERROR));
        $this->assertResponseOk();
        $this->assertResponseContains('"driver":"mercure"');
        $this->assertResponseNotContains('Subscription authorization failed.');
        $this->subscribe([$mount['id'] => $metadata['lease']]);
        $this->assertResponseContains('"grant":null');
        $this->assertResponseContains('Subscription authorization failed.');
    }

    public function testRenderlessPublisherShortcutsRetainStateAndDeliverPrivateMessages(): void
    {
        $mount = $this->mounted(url: '/realtime');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'notify']);
        $this->assertResponseOk();
        $this->assertResponseContains('"html":null');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'output']);
        $this->assertResponseOk();
        $updates = $this->publisher->getUpdates();
        static::assertCount(10, $updates);
        static::assertTrue($updates[0]->isPrivate());
        static::assertSame(['users/1/notifications'], $updates[0]->getTopics());
        static::assertStringContainsString('An update just arrived.', $updates[0]->getData());
        static::assertStringContainsString('"html":false', $updates[1]->getData());
        static::assertStringContainsString('"html":true', $updates[9]->getData());
    }

    public function testPermissionChangesAndSnapshotSubstitutionCannotRenewSubscriptions(): void
    {
        $mount = $this->mounted(url: '/realtime');
        $metadata = $this->metadata();
        $this->subscribe([$mount['id'] => $mount['snapshot']]);
        $this->assertResponseOk();
        $this->assertResponseContains('"grant":null');
        $this->session(['demo' => ['role' => 'viewer']]);
        $this->subscribe([$mount['id'] => $metadata['lease']]);
        $this->assertResponseContains('Subscription authorization failed.');
        $this->assertResponseNotContains('Editing is not allowed.');
        $this->assertResponseContains('"grant":null');
    }

    public function testSubscriptionRouteRequiresCsrfAndValidBatchInput(): void
    {
        $mount = $this->mounted(url: '/realtime');
        $this->requestAsJson();
        $this->post('/_interact/subscribe', json_encode([
            'version' => 1,
            'leases' => [$mount['id'] => 'forged'],
        ], JSON_THROW_ON_ERROR));
        $this->assertResponseCode(403);
        $this->subscribe([]);
        $this->assertResponseCode(400);
        $this->subscribe(array_fill_keys(array_map(strval(...), range(1, 65)), 'forged'));
        $this->assertResponseCode(400);
    }

    public function testOrdinaryPreviewCanSendAndReloadWithoutRealtimeInfrastructure(): void
    {
        putenv('MERCURE_URL');
        $mount = $this->mounted(url: '/realtime');
        $this->assertResponseContains('npm run demo:realtime');
        $this->assertResponseNotContains('data-interact-realtime=');
        $this->assertResponseNotContains('Send private notification');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'action' => 'send',
            'updates' => ['name' => 'Offline preview', 'message' => 'Saved without a hub.'],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('chat-sent');
        $this->assertResponseContains('"html":null');
        $this->get('/realtime');
        $this->assertResponseContains('Saved without a hub.');
    }

    public function testChatValidatesDraftsThenSavesAndPublishesEscapedPersistentMessages(): void
    {
        $mount = $this->mounted(url: '/realtime');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'action' => 'send',
            'updates' => ['name' => '  ', 'message' => '   '],
        ]);
        $this->assertResponseCode(422);
        $payload = Values::map(json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR));
        static::assertSame(['Choose a display name.'], Hash::get($payload, 'errors.name'));
        static::assertSame(['Write a message first.'], Hash::get($payload, 'errors.message'));
        static::assertIsString($payload['html']);
        static::assertSame([], $this->publisher->getUpdates());
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'action' => 'send',
            'updates' => ['name' => '<b>Ada</b>', 'message' => '  Hello <script>bad()</script>  '],
        ]);
        $this->assertResponseOk();
        $payload = Values::map(json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR));
        static::assertSame('', Hash::get($payload, 'state.message'));
        static::assertSame([], $payload['errors']);
        static::assertIsString($payload['html'], 'The first valid send clears previously rendered errors.');
        static::assertSame(['demo/chat'], $this->publisher->getUpdates()[0]->getTopics());
        static::assertStringContainsString('message-sent', $this->publisher->getUpdates()[0]->getData());
        $this->get('/realtime');
        $this->assertResponseContains('&lt;b&gt;Ada&lt;/b&gt;');
        $this->assertResponseContains('Hello &lt;script&gt;bad()&lt;/script&gt;');
        $this->assertResponseNotContains('<script>bad()</script>');
    }

    public function testFailedPublicationStillAcknowledgesTheSavedMessageAndRefreshesTheLocalFeed(): void
    {
        $publisher = $this->createMock(MockPublisher::class);
        $publisher->expects(static::once())->method('publish')->willReturn(false);
        MercurePublisher::setInstance($publisher);
        $mount = $this->mounted(url: '/realtime');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'action' => 'send',
            'updates' => ['message' => 'Saved during an outage.'],
        ]);
        $this->assertResponseOk();
        $payload = Values::map(json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR));
        static::assertSame('', Hash::get($payload, 'state.message'));
        static::assertTrue(Hash::get($payload, 'state.deliveryIssue'));
        static::assertSame('chat-sent', Hash::get($payload, 'events.0.name'));
        static::assertSame([], $payload['errors']);
        $this->get('/realtime');
        $this->assertResponseContains('Saved during an outage.');
    }

    /**
     * Read generated metadata from the real demo markup.
     *
     * @return array<string, mixed> Runtime-owned metadata, never application variables.
     */
    protected function metadata(): array
    {
        $node = new XPath(HTMLDocument::createFromString((string)$this->response?->getBody()))->query(
            '//*[@data-interact-realtime]',
        )
            ->item(0);
        static::assertInstanceOf(Element::class, $node);

        return Values::map(json_decode(
            (string)$node->getAttribute('data-interact-realtime'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Exchange untrusted lease records through the real CSRF-protected route.
     *
     * @param array<array-key, mixed> $leases Client-selected proofs.
     * @param array<string, mixed> $extra Additional untrusted fields.
     */
    protected function subscribe(array $leases, array $extra = []): void
    {
        $this->requestAsJson();
        $this->configRequest(['headers' => ['X-CSRF-Token' => $this->csrf]]);
        $this->post('/_interact/subscribe', json_encode(['version' => 1, 'leases' => (object)$leases]
            + $extra, JSON_THROW_ON_ERROR));
    }
}
