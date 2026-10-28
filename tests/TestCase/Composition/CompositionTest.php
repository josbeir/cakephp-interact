<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Composition;

use Cake\Core\Configure;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;
use Interact\Attribute\State;
use Interact\Composition\BoundValues;
use Interact\Composition\InputTickets;
use Interact\Composition\ModelBindings;
use Interact\Composition\ModelOptions;
use Interact\Composition\PreservedChild;
use Interact\Composition\PropInputs;
use Interact\Composition\Relation;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\Island;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Runtime\RenderFrame;
use Interact\Runtime\Result;
use Interact\Runtime\Runtime;
use Interact\Runtime\SnapshotData;
use Interact\Runtime\Values;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Composition\CountingBindings;
use Interact\Test\Fixture\Composition\HistoryProbeIsland;
use Interact\Test\Fixture\Composition\ParentIsland;
use Interact\Test\Fixture\Composition\ProbeIsland;
use Interact\Test\Fixture\Composition\RequiredIsland;
use Interact\View\TemplateView;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use TestApp\Island\Composition\PickerIsland;

/**
 * Verifies declared parent/child composition through native templates and signed updates.
 * Each child restores its own latest snapshot; parent capabilities never grant access or browser writes to props.
 */
class CompositionTest extends TestCase
{
    protected Runtime $runtime;

    protected IslandContext $context;

    protected SnapshotCodec $codec;

    protected int $time = 1000;

    /**
     * Use the real runtime, deterministic signing, and the same render paths as application islands.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.paths.templates', [
            dirname(__DIR__, 2) . '/Fixture/Composition/templates/',
            APP . 'templates/',
        ]);
        $request = new ServerRequest([
            'url' => '/composition',
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);
        $this->context = new IslandContext($request, 'default', '/_interact/update');
        $this->codec = new SnapshotCodec(str_repeat('p', 32), clock: fn(): int => $this->time);
        $registry = new Registry();
        $registry->register(ParentIsland::class, 'CompositionParent');
        $registry->register(ProbeIsland::class, 'CompositionProbe');

        $this->runtime = new Factory($registry, $this->codec)->create(new TemplateView($request), $this->context);
        ProbeIsland::$constructed = 0;
    }

    /**
     * Open a signed identity as a typed record for focused contract assertions.
     *
     * @return array<string, mixed>
     */
    protected function open(string $snapshot): array
    {
        return $this->codec->open($snapshot, $this->context->binding(), $this->context->scope);
    }

    /**
     * Locate generated metadata independently of application markup.
     */
    protected static function metadataValue(Result $result, string $name): string
    {
        $match = [];
        static::assertSame(1, preg_match('/' . preg_quote($name, '/') . '="([^"]+)"/', $result->html, $match));

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    /**
     * Provide a real typed parent render for mount-binding validation.
     */
    protected function frame(?Island $island = null): RenderFrame
    {
        $island ??= new ParentIsland();

        return new RenderFrame(
            [],
            $island,
            new Definition($island::class),
            new SnapshotData([
                'id' => 'parent',
                'alias' => 'CompositionParent',
                'key' => 'parent',
                'state' => [],
                'children' => [],
            ]),
            $this->context,
        );
    }

    public function testInitialBindingsIncludeAllJsonTypesWithoutMakingPropsWritable(): void
    {
        $mount = $this->runtime->mount('CompositionParent', [], null, $this->context);
        $child = static::metadataValue($mount, 'data-interact-relation');
        $relation = Values::map(json_decode($child, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($mount->id, $relation['parentId']);
        $snapshot = static::metadataValue($mount, 'data-interact-snapshot');
        $data = $this->open($snapshot);
        $this->assertSame('CompositionParent', $data['alias']);
        $children = Values::map($data['children']);
        $identity = Values::map($children['key:probe']);
        $this->assertSame(
            ['value' => '', 'selected' => null, 'flag' => false, 'count' => 0, 'items' => []],
            $identity['values'],
        );
        $this->assertSame(1, ProbeIsland::$constructed);
    }

    public function testParentPropsUseTheLatestChildSnapshotAndReportOneAuthorizedUpdateHook(): void
    {
        $parent = $this->runtime->mount('CompositionParent', [], null, $this->context);
        $snapshots = [];
        preg_match_all('/data-interact-snapshot="([^"]+)"/', $parent->html, $snapshots);
        $child = $this->runtime->update([
            'snapshot' => $snapshots[1][1],
            'updates' => ['notes' => 'Keep my draft'],
        ], $this->context);
        $parent = $this->runtime->update([
            'snapshot' => $parent->snapshot,
            'updates' => ['scope' => 'changed', 'data.value' => 'from parent'],
        ], $this->context);
        $ticket = static::metadataValue($parent, 'data-interact-parent-inputs');
        $next = $this->runtime->update([
            'snapshot' => $child->snapshot,
            'parentInputs' => $ticket,
            'updates' => ['value' => ' newest '],
            'action' => 'normalize',
        ], $this->context);
        $this->assertSame($child->id, $next->id);
        $this->assertSame('changed', $next->state['scope']);
        $this->assertSame('Keep my draft', $next->state['notes']);
        $this->assertSame('NEWEST', $next->state['value']);
        $this->assertSame(['scope', 'value'], $next->state['changed']);
        $this->assertSame(1, $next->state['mounts']);
        $this->assertSame(1, $next->state['calls']);
        $this->assertNull($next->payload()['html']);
        $this->assertStringNotContainsString(
            'data-interact-parent-inputs',
            $this->runtime->update(['snapshot' => $parent->snapshot], $this->context)->html,
        );
    }

    public function testReactivePropsCannotBeChangedThroughOrdinaryBrowserEdits(): void
    {
        $child = $this->runtime->mount('CompositionProbe', [], null, $this->context);
        $this->expectException(InvalidArgumentException::class);
        $this->runtime->update(['snapshot' => $child->snapshot, 'updates' => ['scope' => 'changed']], $this->context);
    }

    /**
     * Report verified parent changes together with the complete URL restoration set in one hook.
     *
     * @param array{'data.value'?: string, 'data.count'?: int} $parentUpdates New accepted parent model values.
     * @param array{paths: list<string>, value: string, count: int} $expected Expected hook observations and normalized state.
     */
    #[DataProvider('historyInputs')]
    public function testHistoryRestorationIncludesChangedParentModels(
        array $parentUpdates,
        array $expected,
        int $page,
    ): void {
        $registry = new Registry();
        $registry->register(ParentIsland::class, 'CompositionParent');
        $registry->register(HistoryProbeIsland::class, 'CompositionProbe');

        $runtime = new Factory($registry, $this->codec)->create(
            new TemplateView($this->context->request),
            $this->context,
        );
        $parent = $runtime->mount('CompositionParent', [], null, $this->context);
        $snapshots = [];
        preg_match_all('/data-interact-snapshot="([^"]+)"/', $parent->html, $snapshots);
        $child = $runtime->update([
            'snapshot' => $snapshots[1][1],
            'updates' => ['notes' => 'Keep this draft'],
        ], $this->context);
        $parent = $runtime->update([
            'snapshot' => $parent->snapshot,
            'updates' => ['scope' => 'changed', ...$parentUpdates],
        ], $this->context);
        $next = $runtime->update([
            'snapshot' => $child->snapshot,
            'parentInputs' => static::metadataValue($parent, 'data-interact-parent-inputs'),
            'urlRestore' => true,
            'updates' => ['page' => $page, 'filter' => ''],
        ], $this->context);
        $this->assertSame($expected['paths'], $next->state['changed']);
        $this->assertSame($expected['value'], $next->state['value']);
        $this->assertSame($expected['count'], $next->state['count']);
        $this->assertSame($page, $next->state['page']);
        $this->assertSame('', $next->state['filter']);
        $this->assertSame('Keep this draft', $next->state['notes']);
        $this->assertSame('changed', $next->state['scope']);
        $this->assertSame(1, $next->state['mounts']);
        $this->assertSame(0, $next->state['calls']);
    }

    /**
     * Cover changed and unchanged URL values with changed and unchanged bound models.
     *
     * @return iterable<string, array{array{'data.value'?: string, 'data.count'?: int}, array{paths: list<string>, value: string, count: int}, int}>
     */
    public static function historyInputs(): iterable
    {
        $updates = ['data.value' => ' from parent ', 'data.count' => 2];
        $expected = ['paths' => ['scope', 'page', 'filter', 'value', 'count'], 'value' => 'FROM PARENT', 'count' => 2];

        yield 'changed URL and changed models' => [$updates, $expected, 2];
        yield 'unchanged URL and changed models' => [$updates, $expected, 1];
        yield 'changed URL and unchanged models' => [
            [],
            ['paths' => ['scope', 'page', 'filter'], 'value' => '', 'count' => 0],
            2,
        ];
    }

    public function testChangedPropsAreAuthorizedBeforeHooksOrActions(): void
    {
        $parent = $this->runtime->mount('CompositionParent', [], null, $this->context);
        $snapshots = [];
        preg_match_all('/data-interact-snapshot="([^"]+)"/', $parent->html, $snapshots);
        $parent = $this->runtime->update([
            'snapshot' => $parent->snapshot,
            'updates' => ['scope' => 'denied'],
        ], $this->context);
        $ticket = static::metadataValue($parent, 'data-interact-parent-inputs');
        $this->expectException(ForbiddenException::class);
        $this->runtime->update([
            'snapshot' => $snapshots[1][1],
            'parentInputs' => $ticket,
            'action' => 'normalize',
        ], $this->context);
    }

    public function testPendingChildUsesLatestSignedInputsAndMountsOnce(): void
    {
        $parent = $this->runtime->mount('CompositionParent', ['loading' => 'manual'], null, $this->context);
        $snapshots = [];
        preg_match_all('/data-interact-snapshot="([^"]+)"/', $parent->html, $snapshots);
        $parent = $this->runtime->update([
            'snapshot' => $parent->snapshot,
            'updates' => ['scope' => 'latest', 'data.value' => 'initial selection'],
        ], $this->context);
        $ticket = static::metadataValue($parent, 'data-interact-parent-inputs');
        $child = $this->runtime->update([
            'snapshot' => $snapshots[1][1],
            'parentInputs' => $ticket,
            'initialize' => true,
        ], $this->context);
        $this->assertSame('latest', $child->state['scope']);
        $this->assertSame('initial selection', $child->state['value']);
        $this->assertSame(1, $child->state['mounts']);
        $this->assertSame([], $child->state['changed']);
    }

    /**
     * Validate the target and all capabilities before constructing application islands.
     *
     * @param array<string, mixed> $patch Change to a correctly signed parent-input payload.
     */
    #[DataProvider('invalidTickets')]
    public function testInvalidParentCapabilitiesFailBeforeConstruction(array $patch): void
    {
        $parent = $this->runtime->mount('CompositionParent', [], null, $this->context);
        $snapshots = [];
        preg_match_all('/data-interact-snapshot="([^"]+)"/', $parent->html, $snapshots);
        $parent = $this->runtime->update([
            'snapshot' => $parent->snapshot,
            'updates' => ['scope' => 'new'],
        ], $this->context);
        $data = array_replace($this->open(static::metadataValue($parent, 'data-interact-parent-inputs')), $patch);
        $ticket = $this->codec->seal($data, $this->context->binding(), $this->context->scope);
        ProbeIsland::$constructed = 0;
        try {
            $this->runtime->update(['snapshot' => $snapshots[1][1], 'parentInputs' => $ticket], $this->context);
            $this->fail('Invalid parent inputs must fail.');
        } catch (InvalidSnapshotException) {
            $this->assertSame(0, ProbeIsland::$constructed);
        }
    }

    /**
     * Supply invalid declarations or transport cases for the corresponding boundary.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidTickets(): iterable
    {
        yield 'wrong purpose' => [['kind' => 'ordinary']];
        yield 'other child' => [['id' => 'other']];
        yield 'other alias' => [['alias' => 'other']];
        yield 'other parent' => [['relation' => ['parentId' => 'other', 'models' => []]]];
        yield 'locked prop' => [['props' => ['identity' => 8]]];
        yield 'unknown prop' => [['props' => ['injected' => 8]]];
        yield 'missing model values' => [['models' => []]];
        yield 'extra model values' => [['models' => ['injected' => 'value']]];
    }

    public function testExpiredTicketCannotUseANewerChildSnapshot(): void
    {
        $parent = $this->runtime->mount('CompositionParent', [], null, $this->context);
        $snapshots = [];
        preg_match_all('/data-interact-snapshot="([^"]+)"/', $parent->html, $snapshots);
        $parent = $this->runtime->update([
            'snapshot' => $parent->snapshot,
            'updates' => ['scope' => 'new'],
        ], $this->context);
        $ticket = static::metadataValue($parent, 'data-interact-parent-inputs');
        $this->time = 2000;
        $child = $this->runtime->update(['snapshot' => $snapshots[1][1]], $this->context);
        $this->time = 3000;
        ProbeIsland::$constructed = 0;
        $this->expectException(InvalidSnapshotException::class);
        $this->runtime->update(['snapshot' => $child->snapshot, 'parentInputs' => $ticket], $this->context);
    }

    public function testStringBindingAndReactiveDefaultsAreNormalized(): void
    {
        $bindings = new ModelBindings();
        $picker = new Definition(PickerIsland::class);
        $parent = new ParentIsland();
        $parent->data['resourceId'] = null;
        $mount = $bindings->prepare([], 'data.resourceId', $picker, $this->frame($parent));
        $this->assertSame(['category' => 'cakephp'], $mount->props);
        $this->assertSame(['value' => null], $mount->bound);
        $this->assertSame('data.resourceId', $mount->relation?->models['value']['path']);
        $this->assertNull($bindings->resolve(null, $picker, null));
        $this->assertNull(Relation::read(null));
        $this->assertSame('allowed', new PropInputs()->props(new Definition(ProbeIsland::class), [])['scope']);
    }

    public function testPreparationReadsCurrentBoundValuesOnceWithoutConstructingTheChild(): void
    {
        $bindings = new CountingBindings();
        $child = new Definition(ProbeIsland::class);
        $parent = new ParentIsland();
        $frame = $this->frame($parent);
        $models = [
            'selected' => 'data.selected',
            'flag' => 'data.flag',
            'count' => 'data.count',
            'items' => 'data.items',
        ];
        $prepared = $bindings->prepare([], $models, $child, $frame);
        $this->assertSame(1, $bindings->relationResolutions);
        $this->assertSame(1, $bindings->resolutions);
        $this->assertSame(['selected' => null, 'flag' => false, 'count' => 0, 'items' => []], $prepared->bound);
        $this->assertSame(0, ProbeIsland::$constructed);
        $this->assertEquals($prepared->relation, $bindings->resolve($models, $child, $frame));
        $this->assertSame(2, $bindings->resolutions);
        $parent->data['count'] = 4;
        $this->assertSame(4, $bindings->prepare([], $models, $child, $frame)->bound['count']);
        $this->assertSame(3, $bindings->resolutions);
    }

    public function testPreparationHonorsCustomResolutionAndRejectsInvalidReplacementValues(): void
    {
        $bindings = $this->createPartialMock(CountingBindings::class, ['resolve']);
        $child = new Definition(ProbeIsland::class);
        $parent = new ParentIsland();
        $frame = $this->frame($parent);
        $relation = new Relation('custom-parent', ['count' => 'data.count']);
        $bindings->expects(static::exactly(2))
            ->method('resolve')
            ->with(null, $child, $frame)
            ->willReturn($relation);
        $prepared = $bindings->prepare([], null, $child, $frame);
        $this->assertSame($relation, $prepared->relation);
        $this->assertSame(['count' => 0], $prepared->bound);
        $this->assertSame(1, $bindings->resolutions);
        $parent->data['count'] = 'invalid';
        try {
            $bindings->prepare([], null, $child, $frame);
            $this->fail('Custom relationships must validate their selected values.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString('expected int', $invalidArgumentException->getMessage());
        }

        $bindings = $this->createPartialMock(ModelBindings::class, ['resolve']);
        $bindings->expects(static::once())
            ->method('resolve')
            ->willThrowException(new InvalidArgumentException('Custom binding validation.'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom binding validation.');
        $bindings->prepare([], null, new Definition(ProbeIsland::class), $this->frame());
    }

    public function testPreparationKeepsValidatedValuesScopedToTheSelectedRelation(): void
    {
        $bindings = new CountingBindings();
        $child = new Definition(ProbeIsland::class);
        $parent = new ParentIsland();
        $frame = $this->frame($parent);
        $invalid = new ParentIsland();
        $invalid->data['count'] = 'invalid';
        $nested = $this->frame($invalid);
        $bindings->afterResolve = static function (?Relation $relation) use ($bindings, $child, $nested): ?Relation {
            try {
                $bindings->prepare([], ['count' => 'data.count'], $child, $nested);
                static::fail('Nested preparation must validate its own parent values.');
            } catch (InvalidArgumentException $invalidArgumentException) {
                static::assertStringContainsString('expected int', $invalidArgumentException->getMessage());
            }

            return $relation;
        };
        $prepared = $bindings->prepare([], ['count' => 'data.count'], $child, $frame);
        $this->assertSame(['count' => 0], $prepared->bound);
        $this->assertSame(2, $bindings->resolutions);
        $this->assertSame(0, ProbeIsland::$constructed);
        $bindings->afterResolve = null;
        $parent->data['count'] = 4;
        $this->assertSame(4, $bindings->prepare([], ['count' => 'data.count'], $child, $frame)->bound['count']);
        $this->assertSame(3, $bindings->resolutions);
        $bindings = new CountingBindings();
        $bindings->afterResolve = static fn(?Relation $relation): Relation => new Relation(
            $relation->parentId ?? 'parent',
            ['selected' => 'data.selected'],
        );
        $prepared = $bindings->prepare(
            [],
            ['count' => 'data.count'],
            new Definition(ProbeIsland::class),
            $this->frame(),
        );
        $this->assertSame(['selected' => null], $prepared->bound);
        $this->assertSame(['selected'], array_keys($prepared->relation->models ?? []));
        $this->assertSame(2, $bindings->resolutions);
    }

    /**
     * @param array<string, mixed>|string|null $model Invalid mount model option.
     */
    #[DataProvider('invalidBindings')]
    public function testInvalidBindingsAreRejectedBeforeMount(string|array|null $model): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelBindings()->prepare([], $model, new Definition(ProbeIsland::class), $this->frame());
    }

    /**
     * Supply invalid declarations or transport cases for the corresponding boundary.
     *
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function invalidBindings(): iterable
    {
        yield 'ambiguous shorthand' => ['data.value'];
        yield 'locked child' => [['notes' => 'data.value']];
        yield 'unknown child' => [['missing' => 'data.value']];
        yield 'unknown parent' => [['value' => 'missing']];
        yield 'locked parent' => [['value' => 'loading']];
        yield 'missing parent path' => [['value' => 'data.missing']];
        yield 'scalar traversal' => [['value' => 'scope.value']];
        yield 'type mismatch' => [['count' => 'scope']];
        yield 'invalid actual value' => [['selected' => 'data.value']];
    }

    /**
     * @param mixed $options Invalid timing/path configuration.
     */
    #[DataProvider('invalidOptions')]
    public function testModelOptionsRejectMalformedConfiguration(mixed $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelOptions()->read($options);
    }

    /**
     * Supply invalid declarations or transport cases for the corresponding boundary.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'missing path' => [[]];
        yield 'numeric path' => [['path' => 7]];
        yield 'unknown timing' => [['path' => 'data.value', 'timing' => 'instant']];
        yield 'noninteger delay' => [['path' => 'data.value', 'delay' => '50']];
        yield 'negative delay' => [['path' => 'data.value', 'timing' => 'live', 'delay' => -1]];
        yield 'excessive delay' => [['path' => 'data.value', 'timing' => 'live', 'delay' => 60_001]];
        yield 'delay without live' => [['path' => 'data.value', 'delay' => 500]];
        yield 'unknown option' => [['path' => 'data.value', 'extra' => true]];
        yield 'unsafe path' => ['data.__proto__'];
    }

    public function testTypedRootBindingsAndLiveConfigurationRetainFalseZeroAndEmptyCollections(): void
    {
        $probe = new ProbeIsland();
        $probe->flag = true;
        $probe->count = 0;

        $relation = new Relation('parent', [
            'flag' => 'flag',
            'count' => ['path' => 'count', 'timing' => 'live', 'delay' => 0],
            'items' => 'items',
        ]);
        $this->assertSame(
            ['flag' => true, 'count' => 0, 'items' => []],
            new BoundValues()->values($relation, new Definition(ProbeIsland::class), $this->frame($probe)),
        );
        $this->assertSame(0, $relation->models['count']['delay'] ?? null);
        $this->assertSame($relation->data(), Relation::read($relation->data())?->data());
    }

    public function testModelBindingNeedsAParent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelBindings()->resolve('data.resourceId', new Definition(PickerIsland::class), null);
    }

    public function testRequiredReactivePropCannotBeOmitted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PropInputs()->props(new Definition(RequiredIsland::class), []);
    }

    public function testUrlOwnedChildModelCannotBeBound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelBindings()->resolve(['value' => 'data.count'], new Definition(RequiredIsland::class), $this->frame());
    }

    public function testModelableStateRequiresWritableCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new State(modelable: true);
    }

    public function testCapabilityIssuanceRequiresAnAuthorizedRenderContext(): void
    {
        $this->expectException(LogicException::class);
        new InputTickets($this->codec)->issue([], [], [], new RenderFrame());
    }

    public function testUninitializedParentModelValuesAreRejected(): void
    {
        $parent = new ParentIsland();
        unset($parent->data);
        $this->expectException(InvalidArgumentException::class);
        new BoundValues()->values(
            new Relation('parent', ['value' => 'data.value']),
            new Definition(ProbeIsland::class),
            $this->frame($parent),
        );
    }

    public function testPreservationCannotSilentlyDropParentInputsWithoutASigner(): void
    {
        $relation = new Relation('parent');
        $definition = new Definition(ProbeIsland::class);
        $previous = [
            'id' => 'child',
            'alias' => $definition->declaration->name,
            'props' => ['scope' => 'old'],
            'relation' => $relation->data(),
            'values' => [],
        ];
        $this->expectException(LogicException::class);
        new PreservedChild(null)->render($definition, ['scope' => 'new'], $previous, $this->frame(), $relation);
    }
}
