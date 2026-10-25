<?php
declare(strict_types=1);

namespace Interact\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use Interact\Http\UpdateHandler;
use Interact\Realtime\SubscriptionHandler;

/**
 * Hosts island updates and subscription authorization inside normal CakePHP middleware scopes.
 *
 * Separate handlers preserve their distinct signed capabilities. Neither endpoint
 * reruns the hosting page controller, and both retain current application authorization.
 */
class InteractController extends Controller
{
    /**
     * Process a versioned island update through its normal request lifecycle.
     */
    public function update(UpdateHandler $handler): Response
    {
        return $handler->handle($this->getRequest(), $this->getResponse());
    }

    /**
     * Exchange subscription-only proofs for renewed leases and provider credentials.
     */
    public function subscribe(SubscriptionHandler $handler): Response
    {
        return $handler->handle($this->getRequest(), $this->getResponse());
    }
}
