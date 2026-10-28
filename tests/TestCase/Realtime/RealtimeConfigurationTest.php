<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Realtime;

use Cake\Container\Container;
use Cake\Core\Configure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Interact\Realtime\BroadcasterInterface;
use Interact\Realtime\Provider\MercureProvider;
use Interact\Realtime\Provider\MercureTokens;
use Interact\Realtime\RealtimeServices;
use Interact\Realtime\SubscriptionAuthorizerInterface;
use LogicException;
use Mercure\Jwt\FirebaseTokenFactory;
use Mercure\Jwt\TokenFactoryInterface;
use Mercure\Service\PublisherInterface as MercurePublisher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies optional provider configuration and application service override boundaries.
 *
 * Missing integrations fail at composition time. Subscriber claims retain explicit
 * application settings without leaking publishing authority into browser credentials.
 */
class RealtimeConfigurationTest extends TestCase
{
    protected mixed $previousMercure;

    protected mixed $previousRealtime;

    /**
     * Isolate composition settings from other CakePHP integration suites.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousMercure = Configure::read('Mercure');
        $this->previousRealtime = Configure::read('Interact.realtime');
        Configure::write('Interact.realtime.provider', 'mercure');
    }

    /**
     * Restore both optional provider settings after success or validation failures.
     */
    protected function tearDown(): void
    {
        Configure::write('Mercure', $this->previousMercure);
        Configure::write('Interact.realtime', $this->previousRealtime);
        parent::tearDown();
    }

    public function testMercureRequiresItsPluginPublisherService(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('load the Mercure plugin first');
        new RealtimeServices()->register(new Container());
    }

    public function testApplicationOverridesArePreservedWhenMercureIsConfigured(): void
    {
        $container = new Container();
        $publisher = $this->createStub(MercurePublisher::class);
        $broadcaster = $this->createStub(BroadcasterInterface::class);
        $authorizer = $this->createStub(SubscriptionAuthorizerInterface::class);
        $container->addShared(MercurePublisher::class, static fn(): MercurePublisher => $publisher);
        $container->addShared(BroadcasterInterface::class, static fn(): BroadcasterInterface => $broadcaster);
        $container->addShared(
            SubscriptionAuthorizerInterface::class,
            static fn(): SubscriptionAuthorizerInterface => $authorizer,
        );
        new RealtimeServices()->register($container);
        $this->assertSame($broadcaster, $container->get(BroadcasterInterface::class));
        $this->assertSame($authorizer, $container->get(SubscriptionAuthorizerInterface::class));
    }

    public function testMissingPublicHubUrlFailsClearly(): void
    {
        $container = new Container();
        $publisher = $this->createMock(MercurePublisher::class);
        $publisher->expects($this->once())->method('getHubUrl')->willReturn('');
        $container->addShared(MercurePublisher::class, static fn(): MercurePublisher => $publisher);
        $container->addShared(
            TokenFactoryInterface::class,
            static fn(): TokenFactoryInterface => new FirebaseTokenFactory(str_repeat('k', 32)),
        );
        Configure::write('Mercure.public_url');
        new RealtimeServices()->register($container);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Configure the public Mercure hub URL');
        $container->get(MercureProvider::class);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}> Invalid public token factory configuration.
     */
    public static function invalidTokenSettings(): iterable
    {
        yield 'secret type' => [['secret' => new stdClass()]];
        yield 'algorithm type' => [['secret' => str_repeat('k', 32), 'algorithm' => false]];
        yield 'factory type' => [['factory' => 42]];
        yield 'missing factory' => [['factory' => 'MissingTokenFactory']];
        yield 'wrong interface' => [['factory' => stdClass::class]];
    }

    #[DataProvider('invalidTokenSettings')]
    public function testInvalidTokenConfigurationCannotConstructCredentials(array $options): void
    {
        Configure::write('Mercure.jwt', $options);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid Mercure token factory configuration');
        new MercureTokens()->create();
    }

    public function testConfiguredClaimsAndApplicationClaimOverridesReachSubscriberTokens(): void
    {
        $secret = str_repeat('k', 32);
        Configure::write('Mercure.jwt', [
            'secret' => $secret,
            'factory' => FirebaseTokenFactory::class,
            'issuer' => 'configured-issuer',
            'audience' => 'configured-audience',
            'subject' => 'configured-subject',
            'client_id' => 'configured-client',
            'additional_claims' => ['iss' => 'explicit-issuer'],
        ]);
        $token = new MercureTokens()->create()
            ->create(['records/1'], [], ['exp' => time() + 300]);
        $claims = JWT::decode($token, new Key($secret, 'HS256'));
        $this->assertSame('explicit-issuer', $claims->iss);
        $this->assertSame('configured-audience', $claims->aud);
        $this->assertSame('configured-subject', $claims->sub);
        $this->assertSame('configured-client', $claims->client_id);
    }
}
