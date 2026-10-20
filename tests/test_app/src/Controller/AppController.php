<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Controller\Controller;
use Interact\View\Helper\InteractHelper;

/**
 * Configures shared controller behavior for the demo application.
 *
 * All demo pages can mount embedded islands through the Interact helper. Individual
 * actions select a different view class only when demonstrating a whole-page island.
 */
class AppController extends Controller
{
    /**
     * Register the island helper once for each demo controller instance.
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->viewBuilder()->addHelper('Interact', ['className' => InteractHelper::class]);
    }
}
