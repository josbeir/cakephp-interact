<?php
declare(strict_types=1);

namespace Interact\Rendering;

use Interact\Discovery\Declaration;
use Interact\Discovery\Definition;
use Interact\Loading\Options;
use Interact\Loading\PlaceholderRenderer;
use Interact\View\TemplateView;

/**
 * Bridges the runtime's explicit render contract to CakePHP's native PHP view engine.
 */
class PhpRenderer implements RendererInterface, PlaceholderRendererInterface, ElementRendererInterface
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

    /**
     * Render a streamed element in an isolated scope through the configured view.
     *
     * @param array<string, mixed> $data Explicit element variables.
     */
    public function element(Definition $definition, string $element, array $data): string
    {
        if (!str_contains($element, '.') && $definition->plugin !== null) {
            $element = $definition->plugin . '.' . $element;
        }

        return $this->view->island(new Declaration($definition->declaration->name, element: $element), $data);
    }

    /**
     * Render a placeholder through the same application-configured isolated view.
     *
     * @param array<string, mixed> $props Declared mount props including defaults.
     */
    public function placeholder(Options $options, array $props): string
    {
        return new PlaceholderRenderer($this->view)->render($options, $props);
    }
}
