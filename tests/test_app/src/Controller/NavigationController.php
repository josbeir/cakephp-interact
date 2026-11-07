<?php
declare(strict_types=1);

namespace TestApp\Controller;

/**
 * Demonstrates document navigation through ordinary CakePHP routes and templates.
 * The shared layout editor is authorized afresh on each destination mount.
 */
class NavigationController extends AppController
{
    /**
     * Render the first page with an independently persistent layout editor.
     */
    public function index(): void
    {
        $this->set('page', 'Overview');
    }

    /**
     * Render another ordinary action using the same example template.
     */
    public function details(): void
    {
        $this->set('page', 'Details');
        $this->viewBuilder()->setTemplate('index');
    }

    /**
     * Demonstrate rotating CSP nonces with a normal CakePHP request and inline styles.
     */
    public function nonces(): void
    {
        $this->set('page', 'Nonce overview');
    }

    /**
     * Supply a fresh nonce and different page styles without restarting the document.
     */
    public function nonceDetails(): void
    {
        $this->set('page', 'Nonce details');
        $this->viewBuilder()->setTemplate('nonces');
    }
}
