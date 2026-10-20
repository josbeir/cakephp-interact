<?php
declare(strict_types=1);

namespace Interact;

use Cake\Http\ServerRequest;

/**
 * Supplies the current request and server-owned update configuration to an island.
 *
 * Authorization reads the current request identity rather than transported state.
 * The session binding and middleware scope keep snapshots within their issuing context.
 */
readonly class IslandContext
{
    /**
     * Keep route configuration server-owned; never accept an endpoint from update payloads.
     */
    public function __construct(public ServerRequest $request, public string $scope, public string $endpoint)
    {
    }

    /**
     * Bind signed state to the current browser session, including anonymous sessions.
     */
    public function binding(): string
    {
        $session = $this->request->getSession();
        $session->start();

        return $session->id();
    }
}
