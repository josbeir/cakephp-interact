<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Events;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Events\InvalidListenerIsland;
use Interact\Test\Fixture\Events\ListenerIsland;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies named dispatch payloads and event-bound actions across runtime security boundaries.
 * Event invocation retains ordinary type checks, authorization, drafts, and rendering policy.
 */
class EventActionsTest extends TestCase
{
    protected function runtime(): Runtime
    {
        $registry = new Registry();
        $registry->register(ListenerIsland::class);

        return new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('e', 32)),
            new CallbackRenderer(static fn(): string => '<p>Events</p>'),
        );
    }

    protected function context(bool $deny = false): IslandContext
    {
        return new IslandContext(new ServerRequest()->withAttribute('deny', $deny), 'events', '/_interact/update');
    }

    public function testDispatchAcceptsNamedAndUnpackedDetailsWithoutChangingTheEventShape(): void
    {
        $island = new ListenerIsland();
        $island->dispatch('article-saved', id: 0, name: '', published: false, note: null, nested: ['items' => []]);

        $detail = ['value' => 'unpacked'];
        $island->dispatch('other', ...$detail);
        $island->dispatch('empty');
        $this->assertSame(
            [
                [
                    'name' => 'article-saved',
                    'detail' => [
                        'id' => 0,
                        'name' => '',
                        'published' => false,
                        'note' => null,
                        'nested' => ['items' => []],
                    ],
                ],
                ['name' => 'other', 'detail' => $detail],
                ['name' => 'empty', 'detail' => []],
            ],
            $island->events(),
        );
    }

    /**
     * @param array<array-key, mixed> $detail Malformed positional or named data.
     */
    #[DataProvider('invalidDetails')]
    public function testDispatchRejectsInvalidDetails(array $detail): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListenerIsland()->dispatch('example', ...$detail);
    }

    /**
     * Supply obsolete positional arrays and non-JSON named details.
     *
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidDetails(): iterable
    {
        yield 'old array syntax' => [[['id' => 1]]];
        yield 'positional scalar' => [[1]];
        yield 'object' => [['value' => new stdClass()]];
        yield 'nonfinite' => [['value' => INF]];
        yield 'unsafe nested key' => [['value' => ['__proto__' => true]]];
        $nested = [];
        for ($depth = 0; $depth < 34; $depth++) {
            $nested = [$nested];
        }

        yield 'depth limit' => [['value' => $nested]];
    }

    public function testMetadataListsAllListenersAndRejectsMalformedDeclarations(): void
    {
        $definition = new Definition(ListenerIsland::class);
        $this->assertSame(['saved', 'refresh'], $definition->listeners->resolve([])['article-saved']);
        $mount = $this->runtime()->mount('Listener', [], null, $this->context());
        $this->assertStringContainsString('data-interact-listeners=', $mount->html);
        $this->assertStringContainsString(
            '&quot;article-saved&quot;:[&quot;saved&quot;,&quot;refresh&quot;]',
            $mount->html,
        );
        $this->expectException(InvalidArgumentException::class);
        new Definition(InvalidListenerIsland::class);
    }

    public function testEventArgumentsBindByNameAndPreserveDraftsAndOptionalDefaults(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Listener', [], null, $context);
        $result = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'saved',
            'event' => 'article-saved',
            'args' => [['published' => true, 'note' => '', 'id' => 0, 'unused' => 'ignored']],
            'updates' => ['draft' => 'keep'],
        ], $context);
        $this->assertSame(
            ['id' => 0, 'title' => 'Default', 'note' => '', 'published' => true],
            $result->state['received'],
        );
        $this->assertSame('keep', $result->state['draft']);
        $this->assertSame([['name' => 'handled', 'detail' => ['id' => 0]]], $result->events);
        $ordinary = $runtime->update([
            'snapshot' => $result->snapshot,
            'action' => 'saved',
            'args' => [42, 'Positional'],
        ], $context);
        $this->assertSame(
            ['id' => 42, 'title' => 'Positional', 'note' => null, 'published' => false],
            $ordinary->state['received'],
        );
    }

    public function testParameterlessHandlersIgnoreValidDetails(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Listener', [], null, $context);
        $result = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'refresh',
            'event' => 'article-saved',
            'args' => [['id' => 1]],
        ], $context);
        $this->assertSame(['refreshed' => true], $result->state['received']);
    }

    public function testRenderlessHandlersAcceptStructuredArgumentsAndValidationStillRenders(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Listener', [], null, $context);
        $result = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'select',
            'event' => 'selection',
            'args' => [['selection' => ['id' => 1], 'score' => 2]],
        ], $context);
        $this->assertNull($result->payload()['html']);
        $this->assertSame(['score' => 2.0, 'selection' => ['id' => 1]], $result->state['received']);
        $invalid = $runtime->update([
            'snapshot' => $result->snapshot,
            'action' => 'invalid',
            'event' => 'invalid',
            'args' => [[]],
        ], $context);
        $this->assertNotSame('', $invalid->html);
        $this->assertSame(['draft' => ['Invalid draft.']], $invalid->errors);
        $this->assertSame([], $invalid->events);
    }

    /**
     * @param array<string, mixed> $patch Tampered event request fields.
     */
    #[DataProvider('invalidInvocations')]
    public function testInvalidEventInvocationsFailBeforeApplicationBehavior(array $patch): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Listener', [], null, $context);
        $this->expectException(InvalidArgumentException::class);
        $runtime->update(array_replace([
            'snapshot' => $mount->snapshot,
            'action' => 'saved',
            'event' => 'article-saved',
            'args' => [['id' => 1]],
        ], $patch), $context);
    }

    /**
     * Supply malformed envelopes, undeclared listeners, and invalid typed inputs.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidInvocations(): iterable
    {
        yield 'not a listener' => [['action' => 'ordinary']];
        yield 'unknown action' => [['action' => 'unknown']];
        yield 'unknown event' => [['event' => 'other']];
        yield 'invalid event type' => [['event' => []]];
        yield 'synchronization' => [['action' => null]];
        yield 'missing detail' => [['args' => []]];
        yield 'extra argument' => [['args' => [['id' => 1], []]]];
        yield 'scalar detail' => [['args' => ['bad']]];
        yield 'list detail' => [['args' => [[1]]]];
        yield 'missing required argument' => [['args' => [[]]]];
        yield 'wrong type' => [['args' => [['id' => '1']]]];
        yield 'invalid null' => [['args' => [['id' => null]]]];
        yield 'unknown unsafe data' => [['args' => [['id' => 1, 'extra' => ['constructor' => true]]]]];
        yield 'initialization' => [['initialize' => true]];
        yield 'history restoration' => [['urlRestore' => true]];
        yield 'upload' => [['upload' => ['kind' => 'clear', 'field' => 'files']]];
    }

    public function testEventActionsReauthorizeTheReceivingIsland(): void
    {
        $runtime = $this->runtime();
        $mount = $runtime->mount('Listener', [], null, $this->context());
        $this->expectException(ForbiddenException::class);
        $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'saved',
            'event' => 'article-saved',
            'args' => [['id' => 1]],
        ], $this->context(true));
    }

    public function testPendingMountsExposeListenersOnlyAfterInitialization(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $pending = $runtime->mount('Listener', [], null, $context, new Options('manual'));
        $this->assertStringNotContainsString('data-interact-listeners', $pending->html);
        $loaded = $runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $context);
        $this->assertStringContainsString('data-interact-listeners', $loaded->html);
        $result = $runtime->update([
            'snapshot' => $loaded->snapshot,
            'action' => 'saved',
            'event' => 'article-saved',
            'args' => [['id' => 1]],
        ], $context);
        $this->assertSame(
            ['id' => 1, 'title' => 'Default', 'note' => null, 'published' => false],
            $result->state['received'],
        );
    }

    public function testEventHandlersRetainStreamingAndRenderlessPolicies(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Listener', [], null, $context);
        $prepared = $runtime->prepare([
            'snapshot' => $mount->snapshot,
            'action' => 'report',
            'event' => 'report',
            'args' => [['id' => 7]],
        ], $context);
        $this->assertTrue($prepared->stream);
        $frames = [];
        $result = $prepared->run(static function (array $message) use (&$frames): void {
            $frames[] = $message;
        });
        $this->assertSame([['type' => 'event', 'name' => 'progress', 'detail' => ['id' => 7]]], $frames);
        $this->assertSame(['id' => 7], $result->state['received']);
        $this->assertNull($result->payload()['html']);
    }
}
