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
     * Demonstrate reusable PHP inputs with a selected loading variation.
     */
    #[Get('/composition', name: 'demo:composition')]
    public function composition(): void
    {
        /** @var mixed $mode */
        $mode = $this->getRequest()->getQuery('loading', 'eager');
        $this->set('pickerLoading', is_string($mode) ? $mode : 'eager');
    }

    /**
     * Render a normal CakePHP page containing the resource-search island.
     */
    #[Get('/search', name: 'demo:search')]
    public function index(): void
    {
    }

    /**
     * Show the same resource listing without exposing filter or paging parameters in URLs.
     */
    #[Get('/search/state-only', name: 'demo:search:state-only')]
    public function stateOnly(): void
    {
    }

    /**
     * Render independent URL-bound listings with explicit per-instance prefixes.
     */
    #[Get('/search/independent', name: 'demo:search:independent')]
    public function independent(): void
    {
    }

    /**
     * Exercise nullable and nested query bindings with independent ownership and persistent navigation.
     */
    #[Get('/demo/url-state', name: 'demo:url-state')]
    public function urlState(): void
    {
    }
}
