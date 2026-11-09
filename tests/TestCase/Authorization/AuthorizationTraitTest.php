<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Authorization;

use Cake\Http\ServerRequest;
use Interact\Discovery\Definition;
use Interact\Test\Fixture\Authorization\BridgeIsland;
use Interact\Test\Fixture\Authorization\ConfiguredIsland;
use Interact\Test\Fixture\Authorization\Resource;
use Interact\Test\TestSuite\Authorization\PolicyFixture;
use PHPUnit\Framework\TestCase;
use PropertyHookType;
use ReflectionProperty;
use Symfony\Component\Process\Process;

/**
 * Verifies trait opt-in, request replacement, extension, and exclusion from transported metadata.
 */
class AuthorizationTraitTest extends TestCase
{
    public function testThePropertyAlwaysUsesTheIslandsCurrentRequest(): void
    {
        $island = new BridgeIsland();
        $island->setRequest(PolicyFixture::request(new ServerRequest()));
        $this->assertTrue($island->Authorization->can(new Resource(), 'access'));
        $island->setRequest(PolicyFixture::request(new ServerRequest(), false));
        $this->assertFalse($island->Authorization->can(new Resource(), 'access'));
    }

    public function testThePropertyCannotBeReplacedOrTransported(): void
    {
        $definition = new Definition(BridgeIsland::class);
        $this->assertSame(['count'], array_keys($definition->properties));
        $property = new ReflectionProperty(BridgeIsland::class, 'Authorization');
        $this->assertTrue($property->isVirtual());
        $this->assertTrue($property->hasHook(PropertyHookType::Get));
        $this->assertFalse($property->hasHook(PropertyHookType::Set));
    }

    public function testAnApplicationCanCustomizeAttributeNamesInItsSharedBaseIsland(): void
    {
        $request = PolicyFixture::request(new ServerRequest());
        $island = new ConfiguredIsland();
        $island->setRequest(
            new ServerRequest()->withAttribute('member', $request->getAttribute('identity'))
                ->withAttribute('policyService', $request->getAttribute('authorization')),
        );
        $this->assertTrue($island->Authorization->can(new Resource(), 'access'));
    }

    public function testThePackageRemainsOptionalUntilAPolicyHelperIsUsed(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Fixture/Authorization/without-package.php']);
        $process->mustRun();
        $this->assertSame('Install cakephp/authorization before using AuthorizationTrait.', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }
}
