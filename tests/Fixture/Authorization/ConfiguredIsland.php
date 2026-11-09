<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authorization;

use Interact\Authorization\IslandAuthorization;

/**
 * Demonstrates configuring a shared application island without overriding initialization.
 */
class ConfiguredIsland extends BridgeIsland
{
    /**
     * Match an application that uses non-default middleware attribute names.
     */
    protected function createAuthorization(): IslandAuthorization
    {
        return new IslandAuthorization($this->getRequest(), 'member', 'policyService');
    }
}
