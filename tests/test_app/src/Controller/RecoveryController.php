<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Http\Response;

/**
 * Demonstrates native navigation through sign-in before mounting fresh recovery state.
 *
 * This fixture has no credentials or real account authentication. Applications use
 * their own sign-in flow, authorization, and session renewal instead.
 */
class RecoveryController extends AppController
{
    /**
     * Offer an explicit native submission without retaining a draft in browser storage or the URL.
     */
    public function signIn(): void {}

    /**
     * Establish a fresh demo session and return to a new page without replaying an action.
     */
    public function completeSignIn(): ?Response
    {
        $session = $this->getRequest()->getSession();
        $session->renew();
        $session->write('demo.recoveryAuthenticated', true);

        return $this->redirect(['_name' => 'demo:playground']);
    }
}
