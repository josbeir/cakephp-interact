<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\State;

use Cake\Http\ServerRequest;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\Runtime;
use Interact\Runtime\Values;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\State\UninitializedStateIsland;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Rejects malformed JSON records, excessive nesting, and uninitialized transported state.
 * These boundaries run through the same declarations and runtime contracts as application islands.
 */
class StateTransportTest extends TestCase
{
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
}
