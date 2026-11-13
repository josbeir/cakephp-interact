<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Recovery;

use Cake\Core\Configure;
use Cake\Error\Renderer\WebExceptionRenderer;
use Interact\Test\TestSuite\Http\HttpTestCase;
use TestApp\Application;

/**
 * Verifies recovery sign-in remains a native, CSRF-protected application workflow.
 *
 * The demonstration endpoint renews the session and redirects to a fresh page;
 * copied drafts and prior actions are never submitted as part of signing in.
 */
class RecoverySignInHttpTest extends HttpTestCase
{
    public function testAnExpiredSignInRedirectsNativePlaygroundVisitsBeforeRenderingIslands(): void
    {
        $this->session(['demo' => ['recoveryAuthenticated' => false]]);
        $this->get('/playground');
        $this->assertResponseCode(302);
        $this->assertRedirect('/recovery/sign-in');
        $this->assertResponseNotContains('data-interact-snapshot');
    }

    public function testSignInUsesANativePostAndReturnsToTheEditor(): void
    {
        $this->get('/recovery/sign-in');
        $this->assertResponseOk();
        $this->assertResponseContains('Continue demo sign-in');
        $this->assertResponseContains('action="/recovery/sign-in"');
        $this->assertResponseNotContains('x-interact:submit');
        $this->enableCsrfToken(Application::CSRF_COOKIE);
        $this->post('/recovery/sign-in');
        $this->assertResponseCode(302);
        $this->assertRedirect('/playground');
        $this->assertSession(true, 'demo.recoveryAuthenticated');
    }

    public function testSignInDoesNotAcceptRequestsWithoutCsrfProtection(): void
    {
        Configure::write('Error.exceptionRenderer', WebExceptionRenderer::class);
        Configure::write('Error.log', false);
        $this->post('/recovery/sign-in');
        $this->assertResponseCode(403);
        $this->assertSession(null, 'demo.recoveryAuthenticated');
    }
}
