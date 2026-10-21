<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Routing\Attribute\Get;

/**
 * Demonstrates document navigation through ordinary CakePHP routes and templates.
 * The shared layout editor is authorized afresh on each destination mount.
 */
class NavigationController extends AppController
{
    /**
     * Render the first page with an independently persistent layout editor.
     */
    #[Get('/navigation', name: 'demo:navigation')]
    public function index(): void
    {
        $this->set('page', 'Overview');
    }

    /**
     * Render another ordinary action using the same example template.
     */
    #[Get('/navigation/details', name: 'demo:navigation-details')]
    public function details(): void
    {
        $this->set('page', 'Details');
        $this->viewBuilder()->setTemplate('index');
    }
}
