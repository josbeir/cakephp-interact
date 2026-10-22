<?php
declare(strict_types=1);

namespace Interact\View;

use Interact\Runtime\Values;
use Interact\View\Helper\InteractHelper;
use InvalidArgumentException;

/**
 * Uses an existing controller-action template as a whole-page island.
 *
 * The initial response includes its normal layout. Later requests render the island
 * content through the update endpoint without executing the page controller again.
 */
class IslandView extends TemplateView
{
    /**
     * Select the island name and props using the ViewBuilder's interact option.
     */
    public function render(?string $template = null, string|false|null $layout = null): string
    {
        /** @var mixed $alias */
        $alias = $this->getConfig('interact.alias');
        /** @var mixed $props */
        $props = $this->getConfig('interact.props', []);
        if (!is_string($alias) || !is_array($props)) {
            throw new InvalidArgumentException('Configure interact.alias and interact.props for a page island.');
        }

        $helper = $this->loadHelper('Interact', ['className' => InteractHelper::class]);
        assert($helper instanceof InteractHelper, 'The configured collaborator must satisfy its declared contract.');
        /** @var mixed $prefix */
        $prefix = $this->getConfig('interact.urlPrefix');
        if ($prefix !== null && !is_string($prefix)) {
            throw new InvalidArgumentException('The page island URL prefix must be a string.');
        }

        $content = $helper->render($alias, Values::map($props), urlPrefix: $prefix);

        return $layout === false || !$this->autoLayout ? $content : $this->renderLayout($content, $layout);
    }
}
