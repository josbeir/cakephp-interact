<?php
declare(strict_types=1);

namespace Interact\Runtime;

/**
 * Tracks one render's child identities while preserving existing children as independent islands.
 */
class RenderFrame
{
    /**
     * @var array<string, array{id: string, alias: string, props: array<string, mixed>, urlPrefix?: string}> Children emitted in this render.
     */
    public array $children = [];

    /**
     * Start a parent render with its previously signed child identities.
     *
     * @param array<string, array{id: string, alias: string, props: array<string, mixed>, urlPrefix?: string}> $previous Verified prior identities.
     */
    public function __construct(public readonly array $previous = [])
    {
    }
}
