<?php
declare(strict_types=1);

namespace Interact\Rendering;

use Interact\Discovery\Definition;
use Interact\View\TemplateView;

/**
 * Bridges the runtime's explicit render contract to CakePHP's native PHP view engine.
 */
class PhpRenderer implements RendererInterface
{
    /**
     * Applications can supply a TemplateView subclass with their own helper initialization.
     */
    public function __construct(protected readonly TemplateView $view)
    {
    }

    /**
     * Render an island through its declared native template or element.
     *
     * @param array<string, mixed> $data Declared state, props, errors, and computed view data.
     */
    public function render(Definition $definition, array $data): string
    {
        return $this->view->island($definition->declaration, $data);
    }
}
