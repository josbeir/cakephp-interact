<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Controller\Controller;
use Cake\Routing\Attribute\Get;
use Interact\View\Helper\InteractHelper;

/**
 * Serves the resource-library demo page while its island owns search and pagination requests.
 */
class ResourcesController extends Controller
{
    /**
     * Render a normal CakePHP page containing the resource-search island.
     */
    #[Get('/search', name: 'demo:search')]
    public function index(): void
    {
        $this->viewBuilder()->addHelper('Interact', ['className' => InteractHelper::class]);
    }
}
