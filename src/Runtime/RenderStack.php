<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Attribute\AsIsland;
use InvalidArgumentException;

/**
 * Tracks nested ownership while native templates render parent and child islands.
 *
 * Existing children retain their signed identities when aliases, props, and sibling keys
 * match. Duplicate keys, changed mount parameters, and excessive recursion are rejected.
 */
class RenderStack
{
    /**
     * @var list<\Interact\Runtime\RenderFrame> Active native template render scopes.
     */
    protected array $frames = [];

    /**
     * Return the current owner when mounting a nested island.
     */
    public function parent(): ?RenderFrame
    {
        return $this->frames === [] ? null : $this->frames[array_key_last($this->frames)];
    }

    /**
     * Reuse a matching child without rerunning its lifecycle or replacing its browser state.
     *
     * @param array<string, mixed> $props Explicit server-owned mount parameters.
     */
    public function preserve(AsIsland $declaration, array $props, string $key): ?Result
    {
        if ($key === '') {
            throw new InvalidArgumentException('Island keys cannot be empty.');
        }

        Values::validate($props);
        $childKey = 'key:' . $key;
        $parent = $this->parent();
        if ($parent instanceof RenderFrame && isset($parent->children[$childKey])) {
            throw new InvalidArgumentException('Duplicate sibling island key.');
        }

        $previous = $parent?->previous[$childKey] ?? null;
        if (!$parent instanceof RenderFrame || $previous === null) {
            return null;
        }

        if ($previous['alias'] !== $declaration->name || $previous['props'] !== $props) {
            throw new InvalidArgumentException('Changing a child alias or props requires a new key.');
        }

        $parent->children[$childKey] = $previous;

        return new Result(
            $previous['id'],
            '<'
                . $declaration->tag
                . ' data-interact-preserve="'
                . htmlspecialchars($previous['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"></'
                . $declaration->tag
                . '>',
            '',
        );
    }

    /**
     * Enter a native render scope, rejecting excessive recursive nesting.
     *
     * @param array<string, array{id: string, alias: string, props: array<string, mixed>}> $previous Verified direct children.
     */
    public function push(array $previous): RenderFrame
    {
        if (count($this->frames) >= 32) {
            throw new InvalidArgumentException('Island nesting exceeds 32 levels.');
        }

        $frame = new RenderFrame($previous);
        $this->frames[] = $frame;

        return $frame;
    }

    /**
     * Release the current frame even if native rendering failed.
     */
    public function pop(): void
    {
        array_pop($this->frames);
    }
}
