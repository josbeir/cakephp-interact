<?php
declare(strict_types=1);

namespace Interact\Authentication;

use Authentication\AuthenticationServiceInterface;
use LogicException;

/**
 * Exposes the application's native authentication service on an island.
 *
 * The get-only property resolves the current request's middleware service without
 * wrapping it, enforcing an identity, or adding service data to snapshots.
 *
 * @require-extends \Interact\Island
 */
trait AuthenticationTrait
{
    /**
     * Access the current middleware service through CakePHP's native interface.
     */
    public AuthenticationServiceInterface $Authentication {
        get => $this->getAuthenticationService();
    }

    /**
     * Resolve the current request's service; override for a custom middleware attribute.
     *
     * @throws LogicException When the optional package or middleware service is unavailable.
     */
    protected function getAuthenticationService(): AuthenticationServiceInterface
    {
        if (!interface_exists(AuthenticationServiceInterface::class)) {
            throw new LogicException('Install cakephp/authentication before using AuthenticationTrait.');
        }

        /** @var mixed $service */
        $service = $this->getRequest()->getAttribute('authentication');
        if (!$service instanceof AuthenticationServiceInterface) {
            throw new LogicException(sprintf(
                'CakePHP Authentication middleware must provide request attribute `authentication` implementing %s.',
                AuthenticationServiceInterface::class,
            ));
        }

        return $service;
    }
}
