<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Action\RouteContext;
use Interact\Composition\ParentValues;
use Interact\Discovery\Declaration;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Loading\PendingMount;
use Interact\Loading\PlaceholderRenderer;
use Interact\Runtime\RenderStack;
use Interact\Runtime\Runtime;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CallbackRenderer;
use Interact\Test\Fixture\ContextRuntime;
use Interact\Test\Fixture\LazyIsland;
use Interact\Test\Fixture\RuntimeReference;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies mount-only tickets and the authorization boundary around deferred rendering.
 * Real signing and fresh instances exercise the same lifecycle used by HTTP updates.
 */
class LazyLoadingTest extends TestCase
{
    protected function context(): IslandContext
    {
        return new IslandContext(
            new ServerRequest(['session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]])]),
            'lazy',
            '/updates',
        );
    }

    protected function runtime(?SnapshotCodec $codec = null): Runtime
    {
        $registry = new Registry();
        $registry->register(LazyIsland::class);

        return new Runtime(
            $registry,
            $codec ?? new SnapshotCodec(str_repeat('l', 32)),
            new CallbackRenderer(static fn($definition, $data): string => '<p>' . (string)$data['count'] . '</p>'),
        );
    }

    protected function setUp(): void
    {
        LazyIsland::$trace = [];
    }

    public function testDeferredMountHasNoStateOrRenderingUntilAuthorizedInitialization(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Lazy', ['recordId' => 4], 'report', $context, new Options('manual'));
        static::assertSame(['authorize:'], LazyIsland::$trace);
        static::assertSame([], $initial->state);
        static::assertSame([], $initial->writable);
        static::assertStringContainsString('data-interact-loading="manual"', $initial->html);
        static::assertStringContainsString('Load content', $initial->html);
        $codec = new SnapshotCodec(str_repeat('l', 32));
        $pending = new PendingMount($codec->open($initial->snapshot, $context->binding(), $context->scope));
        static::assertSame(['recordId' => 4, 'label' => 'Report'], $pending->props);
        $loaded = $runtime->update([
            'snapshot' => $initial->snapshot,
            'initialize' => true,
            'pageQuery' => '?q=latest',
        ], $context);
        static::assertSame(['authorize:', 'authorize:latest', 'mount', 'view'], LazyIsland::$trace);
        static::assertSame($initial->id, $loaded->id);
        static::assertSame('latest', $loaded->state['query']);
        static::assertSame(4, $loaded->state['count']);
        static::assertStringNotContainsString('data-interact-loading', $loaded->html);
        $updated = $runtime->update(['snapshot' => $loaded->snapshot, 'action' => 'increment'], $context);
        static::assertSame(5, $updated->state['count']);
        static::assertSame(
            1,
            count(array_filter(LazyIsland::$trace, static fn(string $item): bool => $item === 'mount')),
        );
    }

    public function testPermissionIsRecheckedBeforeDeferredHooks(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $pending = $runtime->mount('Lazy', ['recordId' => 1], 'report', $context, new Options('lazy'));
        try {
            $runtime->update(
                ['snapshot' => $pending->snapshot, 'initialize' => true],
                new IslandContext($context->request->withAttribute('denied', true), 'lazy', '/updates'),
            );
            static::fail('Permission changes must reject initialization.');
        } catch (ForbiddenException) {
            static::assertSame(['authorize:', 'authorize:'], LazyIsland::$trace);
        }
    }

    public function testInitializationRestoresOuterContextAfterNestedRenderingAndExceptions(): void
    {
        foreach ([false, true] as $fail) {
            $context = $this->context();
            $request = $context->request->withUri($context->request->getUri()->withPath('/reports'))
                ->withParam('controller', 'Reports');
            $context = new IslandContext($request, 'lazy', '/updates');
            $registry = new Registry();
            $registry->register(LazyIsland::class);
            $parents = new ParentValues(['outer' => 'preserved']);
            $reference = new RuntimeReference();
            $renderer = new CallbackRenderer(static function ($definition, array $data) use (
                $reference,
                $context,
                $parents,
                $fail,
            ): string {
                $runtime = $reference->runtime;
                static::assertInstanceOf(ContextRuntime::class, $runtime);
                $active = $runtime->contextState();
                static::assertSame('/reports', $active['path']);
                static::assertSame('?q=current', $active['query']);
                static::assertSame('Reports', $active['routing']?->defaults['controller']);
                static::assertSame($parents, $active['parents']);
                if ($data['count'] === 1) {
                    $runtime->mount('Lazy', ['recordId' => 2], 'nested', $context);
                    if ($fail) {
                        throw new RuntimeException('Render failure');
                    }
                }

                return '<p>Rendered</p>';
            });
            $runtime = new ContextRuntime($registry, new SnapshotCodec(str_repeat('l', 32)), $renderer);
            $reference->runtime = $runtime;
            $pending = $runtime->mount('Lazy', ['recordId' => 1], 'parent', $context, new Options('manual'));
            $runtime->seedContext('/outer', new RouteContext(['controller' => 'Outer']), '?outer=true', $parents);
            $before = $runtime->contextState();
            try {
                $result = $runtime->update([
                    'snapshot' => $pending->snapshot,
                    'initialize' => true,
                    'pageQuery' => '?q=current',
                ], $context);
                static::assertFalse($fail);
                static::assertSame('current', $result->state['query']);
            } catch (RuntimeException $exception) {
                static::assertTrue($fail);
                static::assertSame('Render failure', $exception->getMessage());
            }

            static::assertSame($before, $runtime->contextState());
        }
    }

    public function testInitialPermissionDeniesPlaceholder(): void
    {
        $context = $this->context();
        $this->expectException(ForbiddenException::class);
        $this->runtime()->mount(
            'Lazy',
            ['recordId' => 1],
            'report',
            new IslandContext($context->request->withAttribute('denied', true), 'lazy', '/updates'),
            new Options('defer'),
        );
    }

    /**
     * Supply forbidden combinations rather than treating initialization as a normal sync.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function forbiddenRequests(): iterable
    {
        yield 'ordinary sync' => [[]];
        yield 'action' => [['initialize' => true, 'action' => 'increment']];
        yield 'edits' => [['initialize' => true, 'updates' => ['query' => 'forged']]];
        yield 'arguments' => [['initialize' => true, 'args' => [1]]];
        yield 'history' => [['initialize' => true, 'urlRestore' => true]];
        yield 'upload' => [['initialize' => true, 'upload' => ['kind' => 'remove', 'field' => 'photo']]];
        yield 'marker' => [['initialize' => 'yes']];
        yield 'null marker' => [['initialize' => null]];
    }

    /**
     * Reject pending ticket misuse before mount and rendering hooks.
     *
     * @param array<string, mixed> $input Forbidden browser input.
     */
    #[DataProvider('forbiddenRequests')]
    public function testPendingTicketCannotPerformOtherOperations(array $input): void
    {
        $context = $this->context();
        $runtime = $this->runtime();
        $pending = $runtime->mount('Lazy', ['recordId' => 1], 'report', $context, new Options('lazy'));
        try {
            $runtime->update(['snapshot' => $pending->snapshot] + $input, $context);
            static::fail('Pending ticket misuse must be rejected.');
        } catch (InvalidArgumentException) {
            static::assertSame(['authorize:'], LazyIsland::$trace);
        }
    }

    public function testMountedSnapshotCannotInitializeAgain(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mounted = $runtime->mount('Lazy', ['recordId' => 1], 'report', $context);
        $this->expectException(InvalidArgumentException::class);
        $runtime->update(['snapshot' => $mounted->snapshot, 'initialize' => true], $context);
    }

    public function testExpiredPendingTicketDoesNotRunMount(): void
    {
        $time = 10;
        $codec = new SnapshotCodec(str_repeat('l', 32), 2, static function () use (&$time): int {
            return $time;
        });
        $runtime = $this->runtime($codec);
        $context = $this->context();
        $pending = $runtime->mount('Lazy', ['recordId' => 1], 'report', $context, new Options('defer'));
        $time = 20;
        $this->expectException(InvalidSnapshotException::class);
        $runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $context);
    }

    public function testMissingPropsStillFailBeforePlaceholder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runtime()->mount('Lazy', [], 'report', $this->context(), new Options('lazy'));
    }

    /**
     * Supply invalid application loading configurations.
     *
     * @return iterable<array{string, ?string}>
     */
    public static function invalidOptions(): iterable
    {
        yield ['later', null];
        yield ['eager', 'placeholder'];
        yield ['lazy', ''];
    }

    /**
     * Fail configuration errors before any browser work is emitted.
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptions(string $mode, ?string $placeholder): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Options($mode, $placeholder);
    }

    public function testCustomElementsRequireNativeRenderingSupport(): void
    {
        $this->expectException(LogicException::class);
        new PlaceholderRenderer()->render(new Options('lazy', 'placeholder'), []);
    }

    public function testPendingCapabilitiesRejectStateAndUnexpectedFiles(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $pending = $runtime->mount('Lazy', ['recordId' => 1], 'report', $context, new Options('lazy'));
        $file = new UploadedFile('php://temp', 0, UPLOAD_ERR_OK, 'file.txt');
        try {
            $runtime->update(
                ['snapshot' => $pending->snapshot, 'initialize' => true],
                new IslandContext($context->request->withUploadedFiles(['file' => $file]), 'lazy', '/updates'),
            );
            static::fail('Initialization must reject files.');
        } catch (InvalidArgumentException) {
            static::assertSame(['authorize:'], LazyIsland::$trace);
        }

        $codec = new SnapshotCodec(str_repeat('l', 32));
        $data = $codec->open($pending->snapshot, $context->binding(), $context->scope);
        $data['state'] = ['count' => 1];
        $this->expectException(InvalidArgumentException::class);
        new PendingMount($data);
    }

    public function testPlaceholderCannotMountChildrenAndFailureReleasesTheScope(): void
    {
        $stack = new RenderStack();
        $declaration = new Declaration('child', element: 'child');
        try {
            $stack->placeholder(static fn(): string => $stack->preserve($declaration, [], 'child')->html ?? '');
            static::fail('Placeholder children must not escape into parent ownership.');
        } catch (InvalidArgumentException) {
            static::assertNull($stack->preserve($declaration, [], 'child'));
        }
    }

    public function testPendingPropsPreserveNullAndPageRoutingDefaults(): void
    {
        $context = $this->context();
        $context = new IslandContext(
            $context->request->withAttribute('params', [
                'controller' => 'Reports',
                'action' => 'index',
                'plugin' => null,
                'prefix' => 'Admin',
            ]),
            'lazy',
            '/updates',
        );
        $runtime = $this->runtime();
        $pending = $runtime->mount(
            'Lazy',
            ['recordId' => 0, 'label' => null],
            'report',
            $context,
            new Options('lazy', urlPrefix: 'report'),
        );
        $loaded = $runtime->update([
            'snapshot' => $pending->snapshot,
            'initialize' => true,
            'pageQuery' => '?report_q=current',
        ], $context);
        static::assertNull($loaded->state['label']);
        static::assertSame(0, $loaded->state['count']);
        static::assertSame('current', $loaded->state['query']);
        $data = new SnapshotCodec(str_repeat('l', 32))->open($loaded->snapshot, $context->binding(), $context->scope);
        static::assertSame(
            ['controller' => 'Reports', 'action' => 'index', 'plugin' => null, 'prefix' => 'Admin'],
            $data['routing'],
        );
    }
}
