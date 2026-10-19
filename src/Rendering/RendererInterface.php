<?php
declare(strict_types=1);

namespace Interact\Rendering;

use Interact\Discovery\Definition;

/**
 * Renders application-owned markup with explicit per-island variables and no surrounding layout.
 */
interface RendererInterface
{
    /**
     * Render island content from an explicit variable scope without a surrounding layout.
     *
     * @param array<string, mixed> $data The same explicit variables on mount and update.
     */
    public function render(Definition $definition, array $data): string;
}
