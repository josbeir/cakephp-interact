<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Testing;

use Cake\View\View;
use Interact\Runtime\Factory;
use Interact\View\TemplateView;

/**
 * Marks application-configured native views so the fluent runner cannot substitute its own factory.
 */
class ViewFactory extends Factory
{
    /**
     * Preserve normal rendering and add a native view configuration marker.
     */
    protected function createView(View $host): TemplateView
    {
        $view = parent::createView($host);
        $view->setConfig('testingMarker', 'application view');

        return $view;
    }
}
