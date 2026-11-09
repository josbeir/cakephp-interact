<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authorization;

use Authorization\IdentityInterface;
use Authorization\Policy\Result;
use Authorization\Policy\ResultInterface;

/**
 * Exercises real policy decisions and scope dispatch without substituting an authorization service.
 */
class ResourcePolicy
{
    /**
     * Check the current identity rather than a permission copied into the island snapshot.
     */
    public function canAccess(?IdentityInterface $identity, Resource $resource): ResultInterface
    {
        return new Result(
            $resource->allowed
                && ($resource->public || $identity instanceof IdentityInterface && $identity['allowed'] === true),
            'private-policy-reason',
        );
    }

    /**
     * Return the supplied values unchanged so scope argument forwarding is observable.
     *
     * @return array{resource: Resource, identity: IdentityInterface|null, args: array<array-key, mixed>}
     */
    public function scopeVisible(?IdentityInterface $identity, Resource $resource, mixed ...$args): array
    {
        return ['resource' => $resource, 'identity' => $identity, 'args' => $args];
    }

    /**
     * Preserve a policy's intentional null result without falling back to a second scope call.
     */
    public function scopeEmpty(?IdentityInterface $identity, Resource $resource): null
    {
        return null;
    }
}
