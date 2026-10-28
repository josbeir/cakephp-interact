<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Realtime;

use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Routing\RouteCollection;
use Cake\Routing\Router;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Navigation\Identity;
use Interact\Realtime\LeaseCodec;
use Interact\Realtime\Subscriptions;
use Interact\Runtime\Runtime;
use Interact\Runtime\SnapshotData;
use Interact\Runtime\Values;
use Interact\Security\SnapshotCodec;
use Interact\Streaming\RequestContext;
use Interact\Test\Fixture\Realtime\RealtimeFixtureIsland;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\Runtime\RuntimeReference;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Preserves server-selected subscription endpoints across signed island lifecycles.
 *
 * HTTP-style update contexts intentionally omit helper configuration. Signed
 * identities must retain it for ordinary updates, deferred mounts, and new children.
 */
class RealtimeEndpointTest extends TestCase
{
    protected RouteCollection $previousRoutes;

    protected Registry $registry;

    protected SnapshotCodec $snapshots;

    protected IslandContext $context;

    /**
     * Use a non-default scope and a distinct default route to expose endpoint fallback.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRoutes = Router::getRouteCollection();
        Router::setRouteCollection(clone $this->previousRoutes);
        if (!isset(Router::getRouteCollection()->named()['interact:subscribe'])) {
            Router::createRouteBuilder('/')->post(
                '/_interact/subscribe',
                [
                    'plugin' => 'Interact',
                    'controller' => 'Interact',
                    'action' => 'subscribe',
                ],
                'interact:subscribe',
            );
        }

        $this->registry = new Registry();
        $this->registry->register(RealtimeFixtureIsland::class);

        $this->snapshots = new SnapshotCodec(str_repeat('k', 32));
        $this->context = new IslandContext(
            new ServerRequest(['session' => new Session()]),
            'administration',
            '/admin/islands/update',
            '/admin/islands/subscribe',
        );
    }

    /**
     * Restore routes rather than leaking named endpoint fixtures into other suites.
     */
    protected function tearDown(): void
    {
        Router::setRouteCollection($this->previousRoutes);
        parent::tearDown();
    }

    /**
     * Construct the real signing/rendering workflow with optional nested markup.
     */
    protected function runtime(?CallbackRenderer $renderer = null): Runtime
    {
        $runtime = new Runtime(
            $this->registry,
            $this->snapshots,
            $renderer ?? new CallbackRenderer(static fn(): string => '<p>Current data</p>'),
        );
        $runtime->attachSubscriptions(new Subscriptions(new LeaseCodec(str_repeat('k', 32))));

        return $runtime;
    }

    public function testCustomEndpointSurvivesOrdinaryUpdatesAndBrowserOverridesAreIgnored(): void
    {
        $runtime = $this->runtime();
        $mount = $runtime->mount('RealtimeFixture', ['recordId' => 7], 'root', $this->context);
        $this->assertSame('/admin/islands/subscribe', $mount->realtime['endpoint'] ?? null);
        $context = new RequestContext($this->context->request, 'administration', '/admin/islands/update');
        $result = $runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['draft' => 'changed'],
            'subscriptionEndpoint' => '/untrusted/subscribe',
        ], $context);
        $this->assertSame('/admin/islands/subscribe', $result->realtime['endpoint'] ?? null);
        $data = $this->snapshots->open($result->snapshot, $context->binding(), $context->scope);
        $this->assertSame('/admin/islands/subscribe', $data['subscriptionEndpoint'] ?? null);
        $next = $runtime->update(['snapshot' => $result->snapshot], $context);
        $this->assertSame('/admin/islands/subscribe', $next->realtime['endpoint'] ?? null);
    }

    public function testLazyInitializationRetainsTheCustomEndpointBeforeAnySubscriptionExists(): void
    {
        $runtime = $this->runtime();
        $pending = $runtime->mount(
            'RealtimeFixture',
            ['recordId' => 7],
            'pending',
            $this->context,
            new Options('manual'),
        );
        $this->assertNull($pending->realtime);
        $context = new RequestContext($this->context->request, 'administration', '/admin/islands/update');
        $initialized = $runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $context);
        $this->assertSame('/admin/islands/subscribe', $initialized->realtime['endpoint'] ?? null);
        $next = $runtime->update(['snapshot' => $initialized->snapshot], $context);
        $this->assertSame('/admin/islands/subscribe', $next->realtime['endpoint'] ?? null);
    }

    public function testNewNestedIslandsInheritTheSignedParentSubscriptionEndpoint(): void
    {
        $reference = new RuntimeReference();
        $context = $this->context;
        $reference->runtime = $this->runtime(new CallbackRenderer(static function ($definition, array $data) use (
            $reference,
            &$context,
        ): string {
            return $data['recordId'] === 7
                ? $reference->runtime->mount(
                    'RealtimeFixture',
                    ['recordId' => 8],
                    'child-' . (string)$data['draft'],
                    $context,
                )->html
                : '<p>New child</p>';
        }));
        $mount = $reference->runtime->mount('RealtimeFixture', ['recordId' => 7], 'parent', $context);
        $context = new RequestContext($context->request, 'administration', '/admin/islands/update');
        $result = $reference->runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['draft' => 'new-child'],
        ], $context);
        $nodes = new XPath(HTMLDocument::createFromString(
            '<!doctype html><html><head><title>Endpoint test</title></head><body>' . $result->html . '</body></html>',
        ))->query('//*[@data-interact-realtime]');
        $this->assertSame(2, $nodes->length);
        foreach ($nodes as $node) {
            $this->assertInstanceOf(Element::class, $node);
            $metadata = Values::map(json_decode(
                (string)$node->getAttribute('data-interact-realtime'),
                true,
                flags: JSON_THROW_ON_ERROR,
            ));
            $this->assertSame('/admin/islands/subscribe', $metadata['endpoint']);
        }
    }

    /**
     * @return iterable<string, array{mixed}> Invalid signed transport configuration.
     */
    public static function invalidEndpoints(): iterable
    {
        yield 'empty' => [''];
        yield 'number' => [42];
        yield 'boolean' => [false];
        yield 'record' => [[]];
    }

    #[DataProvider('invalidEndpoints')]
    public function testInvalidSignedEndpointsAreRejected(mixed $endpoint): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotData([
            'alias' => 'RealtimeFixture',
            'id' => 'one',
            'key' => 'root',
            'state' => [],
            'children' => [],
            'subscriptionEndpoint' => $endpoint,
        ]);
    }

    public function testLegacyIdentitiesWithoutTheOptionalEndpointRemainValid(): void
    {
        $data = new SnapshotData([
            'alias' => 'RealtimeFixture',
            'id' => 'one',
            'key' => 'root',
            'state' => [],
            'children' => [],
        ]);
        $this->assertNull($data->subscriptionEndpoint);
    }

    public function testPersistedMountIdentityDistinguishesSubscriptionConfigurations(): void
    {
        $data = ['alias' => 'RealtimeFixture', 'id' => 'one', 'key' => 'root', 'state' => [], 'children' => []];
        $island = new RealtimeFixtureIsland();
        $island->recordId = 7;

        $definition = $this->registry->definition('RealtimeFixture');
        $identity = new Identity();
        $first = $identity->fingerprint(
            $island,
            $definition,
            new SnapshotData($data + ['subscriptionEndpoint' => '/admin/islands/subscribe']),
            $this->context,
        );
        $other = $identity->fingerprint(
            $island,
            $definition,
            new SnapshotData($data + ['subscriptionEndpoint' => '/other/subscribe']),
            $this->context,
        );
        $this->assertNotSame($first, $other);
    }
}
