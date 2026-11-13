<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Http\Response;
use Cake\Routing\Attribute\Get;
use Cake\Routing\Attribute\Post;

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
    #[Get('/recovery/sign-in', name: 'demo:recovery-sign-in')]
    public function signIn(): void {}

    /**
     * Establish a fresh demo session and return to a new page without replaying an action.
     */
    #[Post('/recovery/sign-in', name: 'demo:recovery-sign-in-complete')]
    public function completeSignIn(): ?Response
    {
        $session = $this->getRequest()->getSession();
        $session->renew();
        $session->write('demo.recoveryAuthenticated', true);

        return $this->redirect(['_name' => 'demo:playground']);
    }
}
