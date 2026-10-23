<?php
declare(strict_types=1);

namespace Interact\Streaming;

use Cake\Http\ServerRequest;
use Interact\IslandContext;

/**
 * Captures the session identity before a streamed response releases the session lock.
 *
 * Snapshot signing and nested renders reuse this request-local value without reopening
 * the session after headers have been sent. Session mutations belong before emission.
 */
readonly class RequestContext extends IslandContext
{
    protected SessionBinding $sessionBinding;

    /**
     * Keep the session binding request-local while allowing normal pre-emission renewals.
     */
    public function __construct(ServerRequest $request, string $scope, string $endpoint)
    {
        parent::__construct($request, $scope, $endpoint);
        $this->sessionBinding = new SessionBinding($request->getSession());
    }

    /**
     * Reuse the verified browser binding throughout response emission.
     */
    public function binding(): string
    {
        return $this->sessionBinding->read();
    }

    /**
     * Freeze identity and release the native session immediately before streaming execution.
     */
    public function release(): void
    {
        $this->sessionBinding->release();
    }
}
