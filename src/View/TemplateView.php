<?php
declare(strict_types=1);

namespace Interact\View;

use Cake\View\View;
use Interact\Discovery\Declaration;
use Interact\Pagination\Paginated;
use Interact\View\Helper\PaginatorHelper;
use LogicException;

/**
 * Renders native templates and elements within an explicit island variable scope.
 *
 * Each render receives declared state, props, validation feedback, and computed data.
 * Controller variables are not inherited; the previous view scope is restored on failure.
 *
 * Subclasses selected through view.className retain CakePHP's native constructor signature.
 *
 * @property \Interact\View\Helper\InteractHelper $Interact
 * @property \Interact\View\Helper\PaginatorHelper $Paginator
 */
class TemplateView extends View
{
    /**
     * Keep native form constraints without FormHelper's inline JavaScript validation handlers.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->addHelper('Form', ['autoSetCustomValidity' => false]);
        $this->addHelper('Paginator', ['className' => PaginatorHelper::class]);
    }

    /**
     * Render with island-owned variables, restoring the previous view scope even after a failure.
     *
     * @param array<string, mixed> $data Complete variables for this island render only.
     */
    public function island(Declaration $declaration, array $data): string
    {
        $target =
            $declaration->element
                ?? $declaration->template
                ?? throw new LogicException('Compile the island declaration before rendering an inferred template.');
        $helper = $this->loadHelper('Paginator');
        if (!$helper instanceof PaginatorHelper) {
            throw new LogicException('Island Paginator helpers must extend Interact\\View\\Helper\\PaginatorHelper.');
        }

        $previous = [
            $this->viewVars,
            $this->templatePath,
            $this->subDir,
            $this->plugin,
            $this->current,
            $this->currentType,
        ];
        [$this->plugin] = $this->pluginSplit($target, false);
        $this->templatePath = '';
        $this->subDir = '';
        $this->currentType = static::TYPE_TEMPLATE;
        $this->viewVars = $data;
        $result = null;
        /** @var mixed $value */
        foreach ($data as $value) {
            if (!$value instanceof Paginated) {
                continue;
            }

            $result = $value;
        }

        $restore = $helper->islandScope($result);
        try {
            if ($declaration->element !== null) {
                return $this->element($declaration->element, $data) . ($result?->links->markup() ?? '');
            }

            return (
                $this->renderFile($this->getTemplateFileName($declaration->template), $data)
                    . ($result?->links->markup() ?? '')
            );
        } finally {
            $restore();
            [$this->viewVars, $this->templatePath, $this->subDir, $this->plugin, $this->current, $this->currentType] =
                $previous;
        }
    }
}
