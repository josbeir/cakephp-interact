<?php
declare(strict_types=1);

namespace Interact\Rendering;

use Interact\Discovery\Definition;

/**
 * Offers native element rendering to streaming actions without coupling islands to a view instance.
 */
interface ElementRendererInterface
{
    /**
     * Render an element with explicit variables in the owning island's plugin context.
     *
     * @param array<string, mixed> $data Application-owned element variables.
     */
    public function element(Definition $definition, string $element, array $data): string;
}
