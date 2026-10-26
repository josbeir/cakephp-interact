<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Rendering;

use Cake\Http\ServerRequest;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\Rendering\ShadowIsland;
use Interact\View\IslandView;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Protects authoritative state and requires explicit page-island selection.
 * These boundaries run through the same declarations and runtime contracts as application islands.
 */
class RenderingBoundaryTest extends TestCase
{
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
}
