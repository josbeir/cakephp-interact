<?php
declare(strict_types=1);

namespace Interact;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Supplies immutable path membership checks to the updated() lifecycle hook.
 *
 * Paths describe accepted state edits, reactive props, uploads, or restored URL
 * properties. History restoration can report properties whose values did not change.
 *
 * @implements IteratorAggregate<int, string>
 */
readonly class UpdatedPaths implements IteratorAggregate
{
    /**
     * Preserve the runtime's ordered list of reported paths.
     *
     * @param list<string> $paths Reported paths, matched exactly without parent or wildcard expansion.
     */
    public function __construct(protected array $paths) {}

    /**
     * Check whether the hook received this exact path.
     */
    public function has(string $path): bool
    {
        return in_array($path, $this->paths, true);
    }

    /**
     * Check whether any supplied path was reported; no arguments returns false.
     */
    public function hasAny(string ...$paths): bool
    {
        foreach ($paths as $path) {
            if ($this->has($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the reported paths as an array without exposing mutable storage.
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        return $this->paths;
    }

    /**
     * Iterate over the reported paths in their original order.
     *
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->paths);
    }
}
