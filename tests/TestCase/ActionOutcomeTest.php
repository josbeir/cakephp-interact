<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Http\UriFactory;
use Cake\Routing\RouteCollection;
use Cake\Routing\Router;
use Interact\Action\ActionEffects;
use Interact\Action\DestinationResolver;
use Interact\Action\Outcome;
use Interact\Action\RouteContext;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\RedirectResult;
use Interact\Runtime\Result;
use Interact\Runtime\Runtime;
use Interact\Runtime\SnapshotData;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CallbackRenderer;
use Interact\Test\Fixture\OutcomeIsland;
use Interact\Test\Fixture\OutcomeParentIsland;
use Interact\Test\Fixture\RuntimeReference;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies action capabilities, signed routing defaults, and browser destination validation.
 * Redirect outcomes must not render records deleted by an otherwise successful action.
 */
class ActionOutcomeTest extends TestCase
{
    protected function context(): IslandContext
    {
        $request = new ServerRequest([
            'url' => 'http://localhost/actions',
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);

        return new IslandContext(
            $request->withAttribute('params', [
                'plugin' => null,
                'controller' => 'Articles',
                'action' => 'edit',
                'prefix' => 'Admin',
            ]),
            'outcomes',
            '/_interact/update',
        );
    }

    protected function runtime(): Runtime
    {
        $registry = new Registry();
        $registry->register(OutcomeIsland::class);

        return new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('o', 32)),
            new CallbackRenderer(static fn(): string => '<p>Outcome</p>'),
        );
    }

    public function testRedirectSkipsRenderingSigningAndEvents(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Outcome', [], 'Outcome', $context);
        $result = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'leave',
            'args' => ['https://payment.example/checkout', true],
        ], $context);
        static::assertInstanceOf(RedirectResult::class, $result);
        static::assertSame(
            [
                'version' => 1,
                'id' => $mount->id,
                'redirect' => ['url' => 'https://payment.example/checkout', 'replace' => true],
            ],
            $result->payload(),
        );
        static::assertSame('', $result->snapshot);
        static::assertSame([], $result->events);
    }

    public function testDownloadRendersUpdatedStateAndKeepsRoutingDefaultsSigned(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Outcome', [], 'Outcome', $context);
        $result = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'download',
            'args' => ['/file'],
        ], $context);
        static::assertSame('/file', $result->payload()['download']);
        static::assertSame(1, $result->state['exports']);
        static::assertSame('exported', $result->events[0]['name']);
        $signed = new SnapshotCodec(str_repeat('o', 32))->open($result->snapshot, $context->binding(), $context->scope);
        static::assertSame(
            ['plugin' => null, 'controller' => 'Articles', 'action' => 'edit', 'prefix' => 'Admin'],
            $signed['routing'],
        );
    }

    public function testValidationSuppressesBothOutcomes(): void
    {
        foreach (['redirect', 'download'] as $download) {
            $runtime = $this->runtime();
            $context = $this->context();
            $mount = $runtime->mount('Outcome', [], 'Outcome', $context);
            $result = $runtime->update([
                'snapshot' => $mount->snapshot,
                'action' => 'invalid',
                'args' => [$download],
            ], $context);
            static::assertSame(['draft' => ['Required.']], $result->errors);
            static::assertArrayNotHasKey('redirect', $result->payload());
            static::assertArrayNotHasKey('download', $result->payload());
        }
    }

    public function testRenderErrorsAlsoSuppressDownload(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Outcome', ['hook' => 'render-error'], 'Outcome', $context);
        $result = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'download',
            'args' => ['/file'],
        ], $context);
        static::assertNotEmpty($result->errors);
        static::assertNull($result->download);
    }

    public function testConflictingOutcomesAreRejected(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Outcome', [], 'Outcome', $context);
        $this->expectException(LogicException::class);
        $runtime->update(['snapshot' => $mount->snapshot, 'action' => 'twice'], $context);
    }

    /**
     * Enumerate passive lifecycle phases that cannot request browser outcomes.
     *
     * @return list<array{string}>
     */
    public static function hooks(): array
    {
        return [['authorize'], ['mount'], ['updated'], ['render']];
    }

    #[DataProvider('hooks')]
    public function testHooksCannotRequestOutcomes(string $hook): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $this->expectException(LogicException::class);
        $mount = $runtime->mount('Outcome', ['hook' => $hook], 'Outcome', $context);
        $runtime->update(['snapshot' => $mount->snapshot, 'updates' => ['draft' => 'changed']], $context);
    }

    public function testCapabilityClosesAfterAnException(): void
    {
        $effects = new ActionEffects();
        try {
            $effects->capture(static fn() => throw new LogicException('Application failure.'));
        } catch (LogicException $logicException) {
            static::assertSame('Application failure.', $logicException->getMessage());
        }

        $this->expectException(LogicException::class);
        $effects->record(new Outcome('redirect', '/forbidden'));
    }

    /**
     * Supply unsafe schemes, authorities, and malformed destination inputs.
     *
     * @return list<array{string, string}>
     */
    public static function badDestinations(): array
    {
        return [
            ['redirect', 'javascript:alert(1)'],
            ['redirect', 'data:text/html,test'],
            ['redirect', "https://example.test/\n"],
            ['redirect', 'https://user:password@example.test/'],
            ['redirect', '/\\evil.test'],
            ['redirect', 'http:invalid'],
            ['download', 'https://other.test/file'],
            ['download', 'https://localhost/file'],
        ];
    }

    #[DataProvider('badDestinations')]
    public function testInvalidDestinationsAreRejected(string $kind, string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DestinationResolver()->resolve(
            new Outcome($kind, $url),
            new RouteContext(),
            new UriFactory()->createUri('http://localhost/'),
        );
    }

    public function testNewChildrenInheritTheSignedPageRoutingContext(): void
    {
        $reference = new RuntimeReference();
        $context = $this->context();
        $renderContext = $context;
        $child = null;
        $registry = new Registry();
        $registry->register(OutcomeIsland::class);
        $registry->register(OutcomeParentIsland::class);

        $renderer = new CallbackRenderer(static function ($definition, array $data) use (
            $reference,
            &$renderContext,
            &$child,
        ): string {
            if ($definition->declaration->name === 'OutcomeParent' && $data['exports'] === 1) {
                $child = $reference->runtime->mount('Outcome', [], 'child', $renderContext);

                return $child->html;
            }

            return '<p>Child</p>';
        });
        $reference->runtime = new Runtime($registry, new SnapshotCodec(str_repeat('o', 32)), $renderer);
        $mount = $reference->runtime->mount('OutcomeParent', [], 'parent', $context);
        $updateContext = new IslandContext(
            $context->request->withAttribute('params', [
                'plugin' => 'Interact',
                'controller' => 'Update',
                'action' => 'update',
            ]),
            $context->scope,
            $context->endpoint,
        );
        $renderContext = $updateContext;
        $reference->runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'download',
            'args' => ['/file'],
        ], $updateContext);
        static::assertInstanceOf(Result::class, $child);
        $data = new SnapshotCodec(str_repeat('o', 32))->open($child->snapshot, $context->binding(), $context->scope);
        static::assertSame(RouteContext::fromRequest($context->request)->defaults, $data['routing']);
    }

    public function testRouteResolutionUsesPagePrefixAndPluginInsteadOfEndpointDefaults(): void
    {
        $previous = Router::getRouteCollection();
        Router::setRouteCollection(new RouteCollection());
        try {
            Router::createRouteBuilder('/')->connect('/admin/articles/{action}', [
                'plugin' => 'Blog',
                'prefix' => 'Admin',
                'controller' => 'Articles',
            ]);
            $routing = new RouteContext([
                'plugin' => 'Blog',
                'prefix' => 'Admin',
                'controller' => 'Articles',
                'action' => 'edit',
            ]);
            $url = new DestinationResolver()->resolve(
                new Outcome('redirect', ['action' => 'index', '?' => ['q' => 'cake'], '#' => 'results']),
                $routing,
                new UriFactory()->createUri('http://localhost'),
            );
            static::assertSame('/admin/articles/index?q=cake#results', $url);
        } finally {
            Router::setRouteCollection($previous);
        }
    }

    public function testNativeUrisAndDefaultPortsAreAccepted(): void
    {
        $uri = new UriFactory()->createUri('https://example.test:443/file');
        static::assertSame('https://example.test/file', new DestinationResolver()->resolve(
            new Outcome('download', $uri),
            new RouteContext(),
            $uri,
        ));
    }

    public function testSchemeRelativeDefaultPortDownloadsAreSameOrigin(): void
    {
        $origin = new UriFactory()->createUri('https://example.test/');
        static::assertSame('//example.test:443/file', new DestinationResolver()->resolve(
            new Outcome('download', '//example.test:443/file'),
            new RouteContext(),
            $origin,
        ));
    }

    public function testPartialArraysUsePageDefaultsWithoutChangingNamedRoutes(): void
    {
        $routing = RouteContext::fromRequest($this->context()->request);
        static::assertSame(
            ['plugin' => null, 'prefix' => null],
            RouteContext::fromRequest($this->context()->request->withAttribute('params', [
                'plugin' => false,
                'prefix' => false,
            ]))->defaults,
        );
        static::assertSame(
            ['action' => 'index', 'controller' => 'Articles', 'plugin' => null, 'prefix' => 'Admin'],
            $routing->complete(['action' => 'index']),
        );
        static::assertSame('edit', $routing->complete([])['action']);
        static::assertSame('index', $routing->complete(['controller' => 'Other'])['action']);
        static::assertSame(['_name' => 'article'], $routing->complete(['_name' => 'article']));
        static::assertSame(['_path' => 'Articles::index'], $routing->complete(['_path' => 'Articles::index']));
    }

    /**
     * Exercise signed routing records that violate the restricted metadata schema.
     *
     * @return list<array{mixed}>
     */
    public static function badRoutes(): array
    {
        return [[null], [['user' => 'admin']], [['controller' => []]], [[0 => 'Articles']]];
    }

    #[DataProvider('badRoutes')]
    public function testInvalidSignedRoutingIsRejected(mixed $routing): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotData([
            'alias' => 'Outcome',
            'id' => 'id',
            'key' => 'key',
            'state' => [],
            'children' => [],
            'routing' => $routing,
        ]);
    }
}
