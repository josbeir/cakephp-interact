<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authentication;

use Authentication\AuthenticationServiceInterface;
use LogicException;

/**
 * Resolves an application-owned service attribute through the trait's extension point.
 */
class ConfiguredIsland extends ServiceIsland
{
    /**
     * Match middleware that supplies the service under a custom attribute name.
     */
    protected function getAuthenticationService(): AuthenticationServiceInterface
    {
        /** @var mixed $service */
        $service = $this->getRequest()->getAttribute('memberAuthentication');
        if (!$service instanceof AuthenticationServiceInterface) {
            throw new LogicException('The memberAuthentication service is missing.');
        }

        return $service;
    }
}
