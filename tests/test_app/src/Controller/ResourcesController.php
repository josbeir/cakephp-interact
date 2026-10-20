<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Routing\Attribute\Get;

/**
 * Serves the resource-library demo page while its island owns search and pagination requests.
 */
class ResourcesController extends AppController
{
    /**
     * Render a normal CakePHP page containing the resource-search island.
     */
    #[Get('/search', name: 'demo:search')]
    public function index(): void
    {
    }
}
