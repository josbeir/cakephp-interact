<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Http\Response;
use Cake\Routing\Attribute\Get;
use Cake\Routing\Attribute\Post;
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
    #[Get('/', name: 'demo:index')]
    public function index(): void
    {
        $this->set('title', __('Embedded islands'));
    }

    /**
     * Demonstrate independent article listeners and reactive event-scope changes.
     */
    #[Get('/events', name: 'demo:events')]
    public function events(): void {}

    /**
     * Mount independent examples of typed actions, conditional inputs, and dynamic collections.
     */
    #[Get('/playground', name: 'demo:playground')]
    public function playground(): void {}

    /**
     * Render the documented nested FormHelper example inside the demo layout.
     */
    #[Get('/forms', name: 'demo:forms')]
    public function forms(): void {}

    /**
     * Keep native HTML and FormHelper acceptance fixtures outside the demo navigation.
     */
    #[Get('/demo/form-fixtures', name: 'demo:form-fixtures')]
    public function formFixtures(): void
    {
        $this->render('form_fixtures');
    }

    /**
     * Render real forms and nested islands for deterministic polling acceptance tests.
     */
    #[Get('/demo/polling', name: 'demo:polling-fixtures')]
    public function polling(): void {}

    /**
     * Reuse an action template as the island's whole-page content without recursively embedding it.
     */
    #[Get('/page', name: 'demo:page')]
    public function page(): void
    {
        $this->viewBuilder()
            ->setClassName(IslandView::class)
            ->setOption('interact', ['alias' => 'Article/Page', 'props' => []]);
    }

    /**
     * Test-only endpoint for changing authorization between an initial mount and its next request.
     */
    #[Post('/demo/role/{role}', name: 'demo:role', pass: ['role'])]
    public function role(string $role): Response
    {
        $this->getRequest()->getSession()->write('demo.role', $role);
        $this->disableAutoRender();

        return $this->getResponse()->withType('application/json')->withStringBody('{"ok":true}');
    }

    /**
     * Return a real login redirect so browser tests exercise manual fetch handling consistently.
     */
    #[Post('/demo/redirect', name: 'demo:redirect')]
    public function loginRedirect(): ?Response
    {
        return $this->redirect('/login');
    }
}
