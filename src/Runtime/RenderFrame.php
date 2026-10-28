<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;

/**
 * Tracks one render's child identities while preserving existing children as independent islands.
 */
class RenderFrame
{
    /**
     * @var array<string, array{id: string, alias: string, props: array<string, mixed>, urlPrefix?: string, relation?: array{parentId: string, models: array<string, array{path: string, timing: string, delay?: int}>}, values?: array<string, mixed>}> Children emitted in this render.
     */
    public array $children = [];

    /**
     * Start a parent render with its previously signed child identities.
     *
     * @param array<string, array{id: string, alias: string, props: array<string, mixed>, urlPrefix?: string, relation?: array{parentId: string, models: array<string, array{path: string, timing: string, delay?: int}>}, values?: array<string, mixed>}> $previous Verified prior identities.
     */
    public function __construct(
        public readonly array $previous = [],
        public readonly ?Island $island = null,
        public readonly ?Definition $definition = null,
        public readonly ?SnapshotData $identity = null,
        public readonly ?IslandContext $context = null,
    ) {}
}
