<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Http\Response;
use Interact\View\IslandView;
use function Cake\I18n\__;

/**
 * Demonstrates embedded and whole-page mounting while leaving queries and mutations in their proper
 * owners.
 */
class ArticlesController extends AppController
{
    /**
     * Render the normal index template; its title is not inherited by the embedded islands.
     */
    public function index(): void
    {
        $this->set('title', __('Embedded islands'));
    }

    /**
     * Demonstrate independent article listeners and reactive event-scope changes.
     */
    public function events(): void {}

    /**
     * Mount independent examples of typed actions, conditional inputs, and dynamic collections.
     */
    public function playground(): void {}

    /**
     * Render the documented nested FormHelper example inside the demo layout.
     */
    public function forms(): void {}

    /**
     * Keep native HTML and FormHelper acceptance fixtures outside the demo navigation.
     */
    public function formFixtures(): void
    {
        $this->render('form_fixtures');
    }

    /**
     * Render real forms and nested islands for deterministic polling acceptance tests.
     */
    public function polling(): void {}

    /**
     * Reuse an action template as the island's whole-page content without recursively embedding it.
     */
    public function page(): void
    {
        $this->viewBuilder()
            ->setClassName(IslandView::class)
            ->setOption('interact', ['alias' => 'Article/Page', 'props' => []]);
    }

    /**
     * Test-only endpoint for changing authorization between an initial mount and its next request.
     */
    public function role(string $role): Response
    {
        $this->getRequest()->getSession()->write('demo.role', $role);
        $this->disableAutoRender();

        return $this->getResponse()->withType('application/json')->withStringBody('{"ok":true}');
    }

    /**
     * Return a real login redirect so browser tests exercise manual fetch handling consistently.
     */
    public function loginRedirect(): ?Response
    {
        return $this->redirect('/login');
    }
}
