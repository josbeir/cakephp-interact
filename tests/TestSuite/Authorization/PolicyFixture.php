<?php
declare(strict_types=1);

namespace Interact\Test\TestSuite\Authorization;

use Authorization\AuthorizationService;
use Authorization\IdentityDecorator;
use Authorization\Policy\MapResolver;
use Cake\Http\ServerRequest;
use Interact\Test\Fixture\Authorization\Resource;
use Interact\Test\Fixture\Authorization\ResourcePolicy;

/**
 * Assembles real policy services and decorated identities for authorization feature tests.
 */
class PolicyFixture
{
    /**
     * Resolve the feature resource through CakePHP Authorization's native resolver.
     */
    public static function service(): AuthorizationService
    {
        return new AuthorizationService(new MapResolver([Resource::class => ResourcePolicy::class]));
    }

    /**
     * Add a fresh request service and current identity while preserving native session objects.
     */
    public static function request(ServerRequest $request, ?bool $allowed = true): ServerRequest
    {
        $service = static::service();

        return $request->withAttribute('authorization', $service)->withAttribute(
            'identity',
            $allowed === null ? null : new IdentityDecorator($service, ['allowed' => $allowed]),
        );
    }
}
