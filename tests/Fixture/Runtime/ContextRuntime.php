<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Runtime;

use Interact\Action\RouteContext;
use Interact\Composition\ParentValues;
use Interact\Runtime\Runtime;

/**
 * Exposes ambient runtime context for tests of nested rendering and exception restoration.
 * Application-facing responses still use the real mount, signing, and update lifecycle.
 */
class ContextRuntime extends Runtime
{
    /**
     * Seed an outer request context before executing a pending initialization.
     */
    public function seedContext(string $path, RouteContext $routing, string $query, ParentValues $parents): void
    {
        [$this->pagePath, $this->pageRouting, $this->pageQuery, $this->parentValues] = [
            $path,
            $routing,
            $query,
            $parents,
        ];
    }

    /**
     * Observe routing and parent-input ownership without altering the active context.
     *
     * @return array{path: string|null, routing: RouteContext|null, query: string|null, parents: ParentValues}
     */
    public function contextState(): array
    {
        return [
            'path' => $this->pagePath,
            'routing' => $this->pageRouting,
            'query' => $this->pageQuery,
            'parents' => $this->parentValues,
        ];
    }
}
