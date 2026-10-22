<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\ServerRequest;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\Runtime;
use Interact\Runtime\SnapshotData;
use Interact\Runtime\Values;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CallbackRenderer;
use Interact\Test\Fixture\PlainIsland;
use Interact\Test\Fixture\ShadowIsland;
use Interact\Test\Fixture\UninitializedStateIsland;
use Interact\View\IslandView;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests malformed signed schemas and developer extension mistakes at their public boundaries.
 */
class BoundaryTest extends TestCase
{
    public function testSignedSchemaStillRequiresValidIdentities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotData(['id' => 0]);
    }

    public function testSignedChildrenRequireValidIdentities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotData([
            'alias' => 'a',
            'id' => 'id',
            'key' => 'key',
            'state' => [],
            'children' => ['key' => ['id' => false]],
        ]);
    }

    public function testRecordsRequireStringKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Values::map([0 => 'bad']);
    }

    public function testRecordsRequireAnArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Values::map(null);
    }

    public function testRejectsExcessiveStateDepth(): void
    {
        $value = [];
        for ($index = 0; $index < 34; $index++) {
            $value = [$value];
        }

        $this->expectException(InvalidArgumentException::class);
        Values::validate($value);
    }

    public function testUninitializedStateCannotRender(): void
    {
        $registry = new Registry();
        $registry->register(UninitializedStateIsland::class);

        $runtime = new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('k', 32)),
            new CallbackRenderer(static fn(): string => ''),
        );
        $this->expectException(InvalidArgumentException::class);
        $runtime->mount('UninitializedState', [], 'key', new IslandContext(new ServerRequest(), 'default', '/update'));
    }

    public function testViewDataCannotShadowState(): void
    {
        $registry = new Registry();
        $registry->register(ShadowIsland::class);

        $runtime = new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('k', 32)),
            new CallbackRenderer(static fn(): string => ''),
        );
        $this->expectException(InvalidArgumentException::class);
        $runtime->mount(
            'Shadow',
            ['recordId' => 1],
            'key',
            new IslandContext(new ServerRequest(), 'default', '/update'),
        );
    }

    public function testPageViewRequiresExplicitAlias(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IslandView()->render();
    }

    public function testDefaultViewDataAndInvalidBrowserEvent(): void
    {
        $island = new PlainIsland();
        static::assertSame([], $island->viewData());
        $this->expectException(InvalidArgumentException::class);
        $island->dispatch('bad name');
    }
}
