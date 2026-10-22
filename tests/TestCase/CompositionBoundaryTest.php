<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\Composition\BoundValues;
use Interact\Composition\InputTickets;
use Interact\Composition\Relation;
use Interact\Discovery\Declaration;
use Interact\Discovery\Definition;
use Interact\Island;
use Interact\Runtime\RenderStack;
use Interact\Runtime\SnapshotData;
use Interact\Security\InvalidSnapshotException;
use Interact\Test\Fixture\Composition\ProbeIsland;
use Interact\Test\Fixture\Composition\RequiredIsland;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks malformed composition transport and standalone render-stack extension contracts.
 * Invalid relationship capabilities must fail before application values can be assigned.
 */
class CompositionBoundaryTest extends TestCase
{
    /**
     * Reject malformed signed relationship records through the same reader used by snapshots.
     *
     * @param array<string, mixed> $data Invalid relationship declaration.
     */
    #[DataProvider('invalidRelations')]
    public function testInvalidRelationshipsAreRejected(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        Relation::read($data);
    }

    /**
     * Supply missing identities and nested child names that cannot identify modelable properties.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidRelations(): iterable
    {
        yield 'missing parent' => [['models' => []]];
        yield 'non-string parent' => [['parentId' => 4, 'models' => []]];
        yield 'empty parent' => [['parentId' => '', 'models' => []]];
        yield 'nested child property' => [['parentId' => 'parent', 'models' => ['value.nested' => 'data.value']]];
    }

    /**
     * Reject non-string browser ticket values before attempting signature verification.
     *
     * @param mixed $ticket Invalid browser input.
     */
    #[DataProvider('invalidTokens')]
    public function testParentInputTokensMustBeStrings(mixed $ticket): void
    {
        $this->expectException(InvalidArgumentException::class);
        InputTickets::token(['parentInputs' => $ticket]);
    }

    /**
     * Supply JSON types that cannot carry a signed capability.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'array' => [[]];
        yield 'boolean' => [false];
        yield 'integer' => [7];
    }

    /**
     * Recheck current declarations even when signed field names match the relationship.
     *
     * @param class-string<Island> $class Current application declaration.
     */
    #[DataProvider('unavailableModels')]
    public function testTicketsCannotUseUnavailableModelCapabilities(string $class, string $name): void
    {
        $identity = new SnapshotData([
            'id' => 'child',
            'alias' => 'Probe',
            'key' => 'child',
            'state' => [],
            'children' => [],
            'relation' => new Relation('parent', [$name => 'data.value'])->data(),
        ]);
        $this->expectException(InvalidSnapshotException::class);
        new BoundValues()->validateModels([$name => null], $identity, new Definition($class));
    }

    /**
     * Exercise removed, readonly, non-modelable, and independently URL-owned declarations.
     *
     * @return iterable<string, array{class-string<Island>, string}>
     */
    public static function unavailableModels(): iterable
    {
        yield 'unknown field' => [ProbeIsland::class, 'missing'];
        yield 'readonly prop' => [ProbeIsland::class, 'scope'];
        yield 'non-modelable state' => [ProbeIsland::class, 'notes'];
        yield 'child URL owner' => [RequiredIsland::class, 'value'];
    }

    /**
     * Preserve the declaration-only extension contract without requiring composition signing.
     */
    public function testStandaloneStacksPreserveOrdinaryChildren(): void
    {
        $child = ['id' => 'child', 'alias' => 'Child', 'props' => ['id' => 7]];
        $stack = new RenderStack();
        $frame = $stack->push(['key:child' => $child]);
        $result = $stack->preserve(new Declaration('Child', tag: 'section'), ['id' => 7], 'child');
        static::assertSame('<section data-interact-preserve="child"></section>', $result?->html);
        static::assertSame(['key:child' => $child], $frame->children);
        $stack->pop();
        static::assertNull($stack->parent());
    }

    /**
     * Keep URL ownership immutable for a preserved key.
     */
    public function testChangingAChildUrlPrefixRequiresANewKey(): void
    {
        $stack = new RenderStack();
        $stack->push([
            'key:child' => [
                'id' => 'child',
                'alias' => 'Child',
                'props' => [],
                'urlPrefix' => 'original',
            ],
        ]);
        $this->expectException(InvalidArgumentException::class);
        $stack->preserve(new Declaration('Child'), [], 'child', 'replacement');
    }

    /**
     * Reject recursion beyond the documented render bound while allowing the full limit.
     */
    public function testCompositionCannotExceedTheRenderDepthLimit(): void
    {
        $stack = new RenderStack();
        for ($depth = 0; $depth < 32; $depth++) {
            $frame = $stack->push([]);
            static::assertSame($frame, $stack->parent());
        }

        $this->expectException(InvalidArgumentException::class);
        $stack->push([]);
    }
}
