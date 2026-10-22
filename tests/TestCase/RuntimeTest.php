<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CallbackRenderer;
use Interact\Test\Fixture\CounterIsland;
use Interact\Test\Fixture\RuntimeReference;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies observable mount/update contracts, explicit capabilities, and isolated request state.
 */
class RuntimeTest extends TestCase
{
    /**
     * Create a request with a stable test session and explicit update scope.
     */
    protected function context(string $scope = 'default'): IslandContext
    {
        $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
        $request = new ServerRequest(['session' => $session]);

        return new IslandContext($request->withAttribute('csrfToken', 'csrf-test'), $scope, '/_interact/update');
    }

    /**
     * Assemble a counter runtime with real discovery, signing, and a replaceable renderer.
     */
    protected function runtime(?CallbackRenderer $renderer = null): Runtime
    {
        $registry = new Registry();
        $registry->register(CounterIsland::class);

        return new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('k', 32)),
            $renderer ?? new CallbackRenderer(
                static fn($definition, $data): string => '<p>' . (string)$data['count'] . '</p>',
            ),
        );
    }

    public function testMountAndFreshUpdateLifecycle(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Counter', ['recordId' => 7], 'first', $context);
        static::assertSame('mounted', $initial->state['locked']);
        static::assertStringStartsWith('<section ', $initial->html);
        static::assertStringContainsString('data-interact-csrf="csrf-test"', $initial->html);
        static::assertStringContainsString('x-data="interact"', $initial->html);
        static::assertArrayNotHasKey('locked', $initial->writable);
        $updated = $runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => ['count' => 4],
            'action' => 'increment',
            'args' => [2],
        ], $context);
        static::assertSame(6, $updated->state['count']);
        static::assertSame('count', $updated->state['locked']);
        static::assertSame($initial->id, $updated->id);
        static::assertSame([['name' => 'counter-saved', 'detail' => ['count' => 6]]], $updated->events);
        static::assertSame(1, $updated->payload()['version']);
        $other = $runtime->mount('Counter', ['recordId' => 8], 'other', $context);
        static::assertNotSame($initial->id, $other->id);
        static::assertSame(0, $other->state['count']);
    }

    public function testNestedEditsPreserveScalarDistinctions(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Counter', ['recordId' => 7], 'first', $context);
        $updated = $runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => ['form.title' => '', 'form.tags.0' => 'a', 'amount' => 0, 'enabled' => true],
        ], $context);
        static::assertSame(['title' => '', 'tags' => ['a']], $updated->state['form']);
        static::assertSame(0.0, $updated->state['amount']);
        static::assertTrue($updated->state['enabled']);
        $again = $runtime->update([
            'snapshot' => $updated->snapshot,
            'updates' => ['amount' => null, 'enabled' => false],
        ], $context);
        static::assertNull($again->state['amount']);
        static::assertFalse($again->state['enabled']);
    }

    public function testValidationSuppressesSuccessEvents(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Counter', ['recordId' => 1], 'first', $context);
        $result = $runtime->update(['snapshot' => $initial->snapshot, 'action' => 'invalid'], $context);
        static::assertSame(['count' => ['Invalid count.']], $result->errors);
        static::assertSame([], $result->events);
    }

    public function testPublicLifecycleMethodCannotBeCalledAsAction(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Counter', ['recordId' => 1], 'first', $context);
        $this->expectException(InvalidArgumentException::class);
        $runtime->update(['snapshot' => $initial->snapshot, 'action' => 'mount'], $context);
    }

    public function testLockedStateCannotBeUpdated(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Counter', ['recordId' => 1], 'first', $context);
        $this->expectException(InvalidArgumentException::class);
        $runtime->update(['snapshot' => $initial->snapshot, 'updates' => ['locked' => 'forged']], $context);
    }

    public function testMissingRequiredPropIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runtime()->mount('Counter', [], 'first', $this->context());
    }

    /**
     * Provide malformed state edits and action calls for rejection tests.
     *
     * @return iterable<string, array<array-key, mixed>> Invalid browser inputs.
     */
    public static function invalidUpdates(): iterable
    {
        yield 'wrong int type' => [['count' => '4'], null, []];
        yield 'wrong bool type' => [['enabled' => 'true'], null, []];
        yield 'wrong float type' => [['amount' => 'bad'], null, []];
        yield 'unknown property' => [['unknown' => 0], null, []];
        yield 'nested scalar' => [['count.child' => 1], null, []];
        yield 'traverse scalar' => [['form.title.child' => 1], null, []];
        yield 'invalid path' => [['form..title' => 'x'], null, []];
        yield 'prototype path' => [['form.__proto__.x' => 1], null, []];
        yield 'prototype value' => [['form' => ['constructor' => []]], null, []];
        yield 'object' => [['form' => new stdClass()], null, []];
        yield 'infinite' => [['amount' => INF], null, []];
        yield 'bad action argument type' => [[], 'increment', ['2']];
        yield 'bad action argument count' => [[], 'increment', [1, 2]];
        yield 'missing action arguments' => [[], 'accept', []];
        yield 'sync arguments' => [[], null, [1]];
    }

    /**
     * Reject malformed browser inputs before application hooks or actions can run.
     *
     * @param array<string, mixed> $updates
     * @param list<mixed> $args Reject malformed state before application hooks.
     */
    #[DataProvider('invalidUpdates')]
    public function testInvalidClientInputs(array $updates, ?string $action, array $args): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Counter', ['recordId' => 1], 'first', $context);
        $this->expectException(InvalidArgumentException::class);
        $runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => $updates,
            'action' => $action,
            'args' => $args,
        ], $context);
    }

    public function testNativeActionArguments(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Counter', ['recordId' => 1], 'first', $context);
        $result = $runtime->update([
            'snapshot' => $initial->snapshot,
            'action' => 'accept',
            'args' => ['title', true, ['one'], null],
        ], $context);
        static::assertSame(['title' => 'title', 'tags' => ['one']], $result->state['form']);
        $result = $runtime->update([
            'snapshot' => $result->snapshot,
            'action' => 'accept',
            'args' => ['', false, [], 1.5],
        ], $context);
        static::assertSame(1.5, $result->state['amount']);
    }

    public function testMountRejectsStateAsProps(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runtime()->mount('Counter', ['recordId' => 1, 'count' => 4], 'first', $this->context());
    }

    public function testMountRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runtime()->mount('Counter', ['recordId' => 1], '', $this->context());
    }

    public function testRejectsNonArrayEnvelope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runtime()->update(['snapshot' => 42], $this->context());
    }

    public function testChildParametersRequireANewKey(): void
    {
        $reference = new RuntimeReference();
        $context = $this->context();
        $renderer = new CallbackRenderer(static function ($definition, array $data) use ($reference, $context): string {
            if ($data['recordId'] === 1) {
                $runtime = $reference->runtime;
                assert(is_int($data['count']), 'The configured collaborator must satisfy its declared contract.');

                return $runtime->mount('Counter', ['recordId' => 2 + $data['count']], 'child', $context)->html;
            }

            return '<p>Child</p>';
        });
        $runtime = $this->runtime($renderer);
        $reference->runtime = $runtime;
        $mount = $runtime->mount('Counter', ['recordId' => 1], 'parent', $context);
        $this->expectException(InvalidArgumentException::class);
        $runtime->update(['snapshot' => $mount->snapshot, 'updates' => ['count' => 1]], $context);
    }

    public function testFailedNestedRenderDoesNotLeakTheFrame(): void
    {
        $reference = new RuntimeReference();
        $context = $this->context();
        $renderer = new CallbackRenderer(static function ($definition, array $data) use ($reference, $context): string {
            if ($data['recordId'] === 1) {
                $runtime = $reference->runtime;
                assert(is_int($data['count']), 'The configured collaborator must satisfy its declared contract.');
                $runtime->mount('Counter', ['recordId' => 2], 'same-key', $context);
                $runtime->mount('Counter', ['recordId' => 3], 'same-key', $context);
            }

            return '<p>Child</p>';
        });
        $runtime = $this->runtime($renderer);
        $reference->runtime = $runtime;
        try {
            $runtime->mount('Counter', ['recordId' => 1], 'parent', $context);
            static::fail('Duplicate keys must fail.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            static::assertStringContainsString('Duplicate', $invalidArgumentException->getMessage());
        }

        static::assertStringContainsString(
            'Child',
            $runtime->mount('Counter', ['recordId' => 2], 'parent', $context)->html,
        );
    }
}
