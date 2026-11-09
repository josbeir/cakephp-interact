<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Authorization;

use Authorization\Exception\ForbiddenException;
use Authorization\IdentityDecorator;
use Cake\Http\ServerRequest;
use Interact\Authorization\IslandAuthorization;
use Interact\Test\Fixture\Authorization\CustomIdentity;
use Interact\Test\Fixture\Authorization\Resource;
use Interact\Test\TestSuite\Authorization\PolicyFixture;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks native policy decisions, guest behavior, configuration failures, and required-check tracking.
 */
class IslandAuthorizationTest extends TestCase
{
    public function testAllowedChecksUseTheMiddlewareServiceAndMarkTheRequestChecked(): void
    {
        $service = PolicyFixture::service();
        $identity = new IdentityDecorator($service, ['allowed' => true]);
        $bridge = new IslandAuthorization(
            new ServerRequest()->withAttribute('authorization', $service)
                ->withAttribute('identity', $identity),
        );
        $this->assertFalse($service->authorizationChecked());
        $bridge->authorize(new Resource(), 'access');
        $this->assertTrue($service->authorizationChecked());
        $this->assertTrue($bridge->can(new Resource(), 'access'));
        $this->assertFalse($bridge->can(new Resource(false), 'access'));
        $this->assertSame('private-policy-reason', $bridge->canResult(new Resource(false), 'access')->getReason());
    }

    public function testDenialPreservesTheNativeExceptionAndPolicyResult(): void
    {
        $bridge = new IslandAuthorization(PolicyFixture::request(new ServerRequest(), false));
        try {
            $bridge->authorize(new Resource(), 'access');
            $this->fail('A denied policy must stop application behavior.');
        } catch (ForbiddenException $forbiddenException) {
            $this->assertSame(403, $forbiddenException->getCode());
            $this->assertSame('private-policy-reason', $forbiddenException->getResult()?->getReason());
            $this->assertFalse($forbiddenException->getResult()?->getStatus());
        }
    }

    public function testGuestPoliciesReceiveNullAndCanAllowPublicResources(): void
    {
        $bridge = new IslandAuthorization(PolicyFixture::request(new ServerRequest(), null));
        $this->assertFalse($bridge->can(new Resource(), 'access'));
        $this->assertTrue($bridge->can(new Resource(public: true), 'access'));
        $bridge->authorize(new Resource(public: true), 'access');
        $this->assertTrue($bridge->canResult(new Resource(public: true), 'access')->getStatus());
    }

    public function testScopeArgumentsAndNullResultsArePreservedForIdentitiesAndGuests(): void
    {
        foreach ([true, null] as $allowed) {
            $request = PolicyFixture::request(new ServerRequest(), $allowed);
            $bridge = new IslandAuthorization($request);
            $resource = new Resource();
            $this->assertSame(
                [
                    'resource' => $resource,
                    'identity' => $request->getAttribute('identity'),
                    'args' => [null, false, 0, ''],
                ],
                $bridge->applyScope($resource, 'visible', null, false, 0, ''),
            );
            $this->assertNull($bridge->applyScope($resource, 'empty'));
        }
    }

    public function testApplicationIdentityOverridesAreNotBypassed(): void
    {
        $service = PolicyFixture::service();
        $bridge = new IslandAuthorization(
            new ServerRequest()->withAttribute('authorization', $service)
                ->withAttribute('identity', new CustomIdentity($service, [])),
        );
        $resource = new Resource();
        $this->assertTrue($bridge->can($resource, 'custom'));
        $this->assertFalse($bridge->can($resource, 'access'));
        $this->assertSame('custom-identity', $bridge->canResult($resource, 'custom')->getReason());
        $bridge->authorize($resource, 'custom');
        $this->assertSame(['custom', $resource, false], $bridge->applyScope($resource, 'custom', false));
    }

    public function testSkippingAuthorizationIsExplicitAndUsesTheExistingService(): void
    {
        $service = PolicyFixture::service();
        $bridge = new IslandAuthorization(new ServerRequest()->withAttribute('authorization', $service));
        $this->assertFalse($service->authorizationChecked());
        $this->assertSame($bridge, $bridge->skipAuthorization());
        $this->assertTrue($service->authorizationChecked());
        $this->assertFalse($bridge->can(new Resource(), 'access'));
    }

    /**
     * Supply absent services, wrong service types, and undecorated identities.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidAttributes(): iterable
    {
        yield 'missing middleware' => ['authorization', null];
        yield 'wrong service' => ['authorization', false];
        yield 'undecorated identity' => ['identity', ['allowed' => true]];
    }

    #[DataProvider('invalidAttributes')]
    public function testInvalidMiddlewareConfigurationFailsClosed(string $attribute, mixed $value): void
    {
        $request = PolicyFixture::request(new ServerRequest())->withAttribute($attribute, $value);
        $this->expectException(LogicException::class);
        try {
            new IslandAuthorization($request)->can(new Resource(), 'access');
        } catch (LogicException $logicException) {
            $this->assertStringContainsString($attribute, $logicException->getMessage());
            throw $logicException;
        }
    }
}
