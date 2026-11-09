<?php
declare(strict_types=1);

namespace Interact\Authorization;

use Authorization\AuthorizationServiceInterface;
use Authorization\Exception\ForbiddenException;
use Authorization\IdentityInterface;
use Authorization\Policy\ResultInterface;
use Cake\Http\ServerRequest;
use LogicException;

/**
 * Adapts CakePHP Authorization's middleware to explicit island policy checks.
 *
 * Policies, identity decoration, and required-check tracking belong to the application
 * middleware. This request-local bridge neither grants access nor skips checks implicitly.
 */
class IslandAuthorization
{
    /**
     * Receive the current request and the application's middleware attribute names.
     */
    public function __construct(
        protected readonly ServerRequest $request,
        protected readonly string $identityAttribute = 'identity',
        protected readonly string $serviceAttribute = 'authorization',
    ) {}

    /**
     * Require a policy decision, preserving the native exception and its result on denial.
     *
     * @throws ForbiddenException When the policy denies access.
     * @throws LogicException When the optional dependency or middleware is misconfigured.
     */
    public function authorize(mixed $resource, string $action): void
    {
        $result = $this->canResult($resource, $action);
        if ($result->getStatus()) {
            return;
        }

        $name = match (true) {
            is_object($resource) => $resource::class,
            is_string($resource) => $resource,
            default => gettype($resource),
        };
        throw new ForbiddenException($result, [$action, $name]);
    }

    /**
     * Check permission without throwing for an ordinary policy denial.
     *
     * @throws LogicException When the optional dependency or middleware is misconfigured.
     */
    public function can(mixed $resource, string $action): bool
    {
        $service = $this->getService();
        $identity = $this->getIdentity();

        return $identity instanceof IdentityInterface
            ? $identity->can($action, $resource)
            : $service->can(null, $action, $resource);
    }

    /**
     * Return the native policy result for application-owned feedback or decisions.
     *
     * @throws LogicException When the optional dependency or middleware is misconfigured.
     */
    public function canResult(mixed $resource, string $action): ResultInterface
    {
        $service = $this->getService();
        $identity = $this->getIdentity();

        return $identity instanceof IdentityInterface
            ? $identity->canResult($action, $resource)
            : $service->canResult(null, $action, $resource);
    }

    /**
     * Apply a native policy scope, forwarding scope arguments for guests and identities alike.
     *
     * @throws LogicException When the optional dependency or middleware is misconfigured.
     */
    public function applyScope(mixed $resource, string $action, mixed ...$args): mixed
    {
        $service = $this->getService();
        $identity = $this->getIdentity();

        return $identity instanceof IdentityInterface
            ? $identity->applyScope($action, $resource, ...$args)
            : $service->applyScope(null, $action, $resource, ...$args);
    }

    /**
     * Explicitly acknowledge that the whole HTTP request needs no policy check.
     *
     * This updates middleware bookkeeping; it never bypasses another island's checks.
     *
     * @return $this
     * @throws LogicException When the optional dependency or middleware is misconfigured.
     */
    public function skipAuthorization(): static
    {
        $this->getService()->skipAuthorization();

        return $this;
    }

    /**
     * Require the application's existing middleware service instead of constructing a new one.
     *
     * @throws LogicException When the package or middleware service is unavailable.
     */
    protected function getService(): AuthorizationServiceInterface
    {
        if (!interface_exists(AuthorizationServiceInterface::class)) {
            throw new LogicException('Install cakephp/authorization before using AuthorizationTrait.');
        }

        /** @var mixed $service */
        $service = $this->request->getAttribute($this->serviceAttribute);
        if (!$service instanceof AuthorizationServiceInterface) {
            throw new LogicException(sprintf(
                'CakePHP Authorization middleware must provide request attribute `%s` implementing %s.',
                $this->serviceAttribute,
                AuthorizationServiceInterface::class,
            ));
        }

        return $service;
    }

    /**
     * Accept guest policies while rejecting an undecorated or invalid application identity.
     *
     * @throws LogicException When a non-null identity does not implement the plugin contract.
     */
    protected function getIdentity(): ?IdentityInterface
    {
        /** @var mixed $identity */
        $identity = $this->request->getAttribute($this->identityAttribute);
        if ($identity !== null && !$identity instanceof IdentityInterface) {
            throw new LogicException(sprintf(
                'Request attribute `%s` must implement %s or be null.',
                $this->identityAttribute,
                IdentityInterface::class,
            ));
        }

        return $identity;
    }
}
