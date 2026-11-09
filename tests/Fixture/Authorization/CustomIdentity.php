<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authorization;

use Authorization\IdentityDecorator;
use Authorization\Policy\Result;
use Authorization\Policy\ResultInterface;

/**
 * Supplies application-owned overrides that the bridge must honor like the controller component.
 */
class CustomIdentity extends IdentityDecorator
{
    /**
     * Recognize a policy action handled by the application identity itself.
     */
    public function can(string $action, mixed $resource): bool
    {
        return $action === 'custom';
    }

    /**
     * Preserve the application's result instead of bypassing the decorator through the service.
     */
    public function canResult(string $action, mixed $resource): ResultInterface
    {
        return new Result($action === 'custom', 'custom-identity');
    }

    /**
     * Retain the application's scope implementation and its additional arguments.
     *
     * @return array<array-key, mixed>
     */
    public function applyScope(string $action, mixed $resource, mixed ...$optionalArgs): array
    {
        return [$action, $resource, ...$optionalArgs];
    }
}
