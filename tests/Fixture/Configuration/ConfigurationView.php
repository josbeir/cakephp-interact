<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Configuration;

use Cake\View\Helper\TextHelper;
use Interact\View\TemplateView;

/**
 * Registers an application presentation helper through the native view lifecycle.
 *
 * @property \Cake\View\Helper\TextHelper $Presentation
 */
class ConfigurationView extends TemplateView
{
    /**
     * Preserve Interact helpers while adding an application-owned alias.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->addHelper('Presentation', ['className' => TextHelper::class]);
    }
}
