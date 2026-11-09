<?php
declare(strict_types=1);

namespace Interact\Authorization;

/**
 * Exposes CakePHP policy checks on an island without changing its lifecycle.
 *
 * Add this trait to an application AppIsland or an individual island. The virtual
 * property creates a bridge for the current request and never becomes snapshot state.
 *
 * @require-extends \Interact\Island
 */
trait AuthorizationTrait
{
    /**
     * Access policy helpers using the current middleware service and identity.
     */
    public IslandAuthorization $Authorization {
        get => $this->createAuthorization();
    }

    /**
     * Override to customize the request attribute names or supply a bridge subclass.
     */
    protected function createAuthorization(): IslandAuthorization
    {
        return new IslandAuthorization($this->getRequest());
    }
}
