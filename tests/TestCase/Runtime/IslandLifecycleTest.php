<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Runtime;

use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\Runtime\CounterIsland;
use Interact\Test\Fixture\Runtime\RuntimeReference;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies observable mount/update contracts, explicit capabilities, and isolated request state.
 */
class IslandLifecycleTest extends TestCase
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
     * Assemble a counter runtime with real class resolution, signing, and a replaceable renderer.
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
        $this->assertSame('mounted', $initial->state['locked']);
        $this->assertStringStartsWith('<section ', $initial->html);
        $this->assertStringContainsString('data-interact-csrf="csrf-test"', $initial->html);
        $this->assertStringContainsString('x-data="interact"', $initial->html);
        $this->assertArrayNotHasKey('locked', $initial->writable);
        $updated = $runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => ['count' => 4],
            'action' => 'increment',
            'args' => [2],
        ], $context);
        $this->assertSame(6, $updated->state['count']);
        $this->assertSame('count', $updated->state['locked']);
        $this->assertSame($initial->id, $updated->id);
        $this->assertSame([['name' => 'counter-saved', 'detail' => ['count' => 6]]], $updated->events);
        $this->assertSame(1, $updated->payload()['version']);
        $other = $runtime->mount('Counter', ['recordId' => 8], 'other', $context);
        $this->assertNotSame($initial->id, $other->id);
        $this->assertSame(0, $other->state['count']);
    }

    public function testNestedEditsPreserveScalarDistinctions(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Counter', ['recordId' => 7], 'first', $context);
        $updated = $runtime->update([
            'snapshot' => $initial->snapshot,
            'updates' => [
                'form.title' => '',
                'form.tags.0' => 'a',
                'form.address.city' => 'Ghent',
                'amount' => 0,
                'enabled' => true,
            ],
        ], $context);
        $this->assertSame(
            ['title' => '', 'tags' => ['a'], 'address' => ['city' => 'Ghent']],
            $updated->state['form'],
        );
        $this->assertSame(0.0, $updated->state['amount']);
        $this->assertTrue($updated->state['enabled']);
        $again = $runtime->update([
            'snapshot' => $updated->snapshot,
            'updates' => ['amount' => null, 'enabled' => false, 'form.address' => null],
        ], $context);
        $this->assertNull($again->state['amount']);
        $this->assertFalse($again->state['enabled']);
        $this->assertSame(['title' => '', 'tags' => ['a'], 'address' => null], $again->state['form']);
    }

    public function testWholeDraftAndDottedEditsReportTheSameNestedPath(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Counter', ['recordId' => 1], 'first', $context);
        foreach ([['form.title' => 'Changed'], ['form' => ['title' => 'Changed', 'tags' => []]]] as $updates) {
            $result = $runtime->update(['snapshot' => $mount->snapshot, 'updates' => $updates], $context);
            $this->assertSame('form.title', $result->state['locked']);
            $this->assertSame(['title' => 'Changed', 'tags' => []], $result->state['form']);
        }
    }

    public function testRevertedAndNormalizedValuesDoNotInvokeUpdated(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Counter', ['recordId' => 1], 'first', $context);
        $reverted = $runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['form.title' => 'Temporary', 'form' => ['title' => '', 'tags' => []]],
        ], $context);
        $this->assertSame('mounted', $reverted->state['locked']);
        $first = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'accept',
            'args' => ['', false, [], 0],
        ], $context);
        $next = $runtime->update(['snapshot' => $first->snapshot, 'updates' => ['amount' => 0]], $context);
        $this->assertSame(0.0, $next->state['amount']);
        $this->assertSame('mounted', $next->state['locked']);
    }

    public function testValidationSuppressesSuccessEvents(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $initial = $runtime->mount('Counter', ['recordId' => 1], 'first', $context);
        $result = $runtime->update(['snapshot' => $initial->snapshot, 'action' => 'invalid'], $context);
        $this->assertSame(['count' => ['Invalid count.']], $result->errors);
        $this->assertSame([], $result->events);
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
        yield 'traverse explicit null' => [['form.address' => null, 'form.address.city' => 'Ghent'], null, []];
        yield 'invalid path' => [['form..title' => 'x'], null, []];
        yield 'prototype path' => [['form.__proto__.x' => 1], null, []];
        yield 'prototype value' => [['form' => ['constructor' => []]], null, []];
        yield 'object' => [['form' => new stdClass()], null, []];
        yield 'infinite' => [['amount' => INF], null, []];
        yield 'bad action argument type' => [[], 'increment', ['2']];
        yield 'bad action argument count' => [[], 'increment', [1, 2]];
        yield 'missing action arguments' => [[], 'accept', []];
        yield 'sync arguments' => [[], null, [1]];
        yield 'setup hook' => [[], 'initialize', []];
        yield 'table lookup' => [[], 'fetchTable', ['Articles']];
        yield 'table locator access' => [[], 'getTableLocator', []];
        yield 'table locator replacement' => [[], 'setTableLocator', [null]];
        yield 'request access' => [[], 'getRequest', []];
        yield 'request replacement' => [[], 'setRequest', [null]];
        yield 'request state' => [['request' => []], null, []];
        yield 'logging helper' => [[], 'log', ['message']];
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
        $this->assertSame(['title' => 'title', 'tags' => ['one']], $result->state['form']);
        $result = $runtime->update([
            'snapshot' => $result->snapshot,
            'action' => 'accept',
            'args' => ['', false, [], 1.5],
        ], $context);
        $this->assertSame(1.5, $result->state['amount']);
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
            $this->fail('Duplicate keys must fail.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString('Duplicate', $invalidArgumentException->getMessage());
        }

        $this->assertStringContainsString(
            'Child',
            $runtime->mount('Counter', ['recordId' => 2], 'parent', $context)->html,
        );
    }
}
