<?php
declare(strict_types=1);

namespace TestApp\Controller;

/**
 * Serves the resource-library demo page while its island owns search and pagination requests.
 */
class ResourcesController extends AppController
{
    /**
     * Demonstrate reusable PHP inputs with a selected loading variation.
     */
    public function composition(): void
    {
        /** @var mixed $mode */
        $mode = $this->getRequest()->getQuery('loading', 'eager');
        $this->set('pickerLoading', is_string($mode) ? $mode : 'eager');
    }

    /**
     * Render a normal CakePHP page containing the resource-search island.
     */
    public function index(): void {}

    /**
     * Show the same resource listing without exposing filter or paging parameters in URLs.
     */
    public function stateOnly(): void {}

    /**
     * Render independent URL-bound listings with explicit per-instance prefixes.
     */
    public function independent(): void {}

    /**
     * Show two independently configured Table listings within one island.
     */
    public function multiple(): void {}

    /**
     * Exercise nullable and nested query bindings with independent ownership and persistent navigation.
     */
    public function urlState(): void {}
}
