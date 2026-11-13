<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Authentication;

use Authentication\AuthenticationService;
use Cake\Http\ServerRequest;
use Error;
use Interact\Discovery\Definition;
use Interact\Test\Fixture\Authentication\ConfiguredIsland;
use Interact\Test\Fixture\Authentication\ServiceIsland;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PropertyHookType;
use ReflectionProperty;
use Symfony\Component\Process\Process;

/**
 * Verifies direct native-service access, optional dependencies, and request-local ownership.
 */
class AuthenticationTraitTest extends TestCase
{
    public function testThePropertyReturnsTheExactServiceFromTheCurrentRequest(): void
    {
        $first = new AuthenticationService();
        $second = new AuthenticationService();
        $island = new ServiceIsland()->setRequest(new ServerRequest()->withAttribute('authentication', $first));
        $this->assertSame($first, $island->Authentication);
        $this->assertSame($first, $island->Authentication);

        $island->setRequest(new ServerRequest()->withAttribute('authentication', $second));
        $this->assertSame($second, $island->Authentication);
    }

    public function testThePropertyIsVirtualAndExcludedFromTransportedDeclarations(): void
    {
        $definition = new Definition(ServiceIsland::class);
        $this->assertSame(['count'], array_keys($definition->properties));
        $property = new ReflectionProperty(ServiceIsland::class, 'Authentication');
        $this->assertTrue($property->isVirtual());
        $this->assertTrue($property->hasHook(PropertyHookType::Get));
        $this->assertFalse($property->hasHook(PropertyHookType::Set));
    }

    public function testTheServicePropertyCannotBeAssigned(): void
    {
        $island = new ServiceIsland();
        $property = new ReflectionProperty(ServiceIsland::class, 'Authentication');
        $this->expectException(Error::class);
        $property->setValue($island, new AuthenticationService());
    }

    public function testAnApplicationCanOverrideTheServiceLookup(): void
    {
        $service = new AuthenticationService();
        $island = new ConfiguredIsland()->setRequest(
            new ServerRequest()->withAttribute('memberAuthentication', $service),
        );
        $this->assertSame($service, $island->Authentication);
    }

    /**
     * Supply missing and invalid middleware attributes without treating them as guest services.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidServices(): iterable
    {
        yield 'missing service' => [null];
        yield 'invalid service' => [false];
    }

    #[DataProvider('invalidServices')]
    public function testMissingOrInvalidServicesFailClearly(mixed $service): void
    {
        $island = new ServiceIsland()->setRequest(new ServerRequest()->withAttribute('authentication', $service));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches(
            '/CakePHP Authentication middleware must provide request attribute `authentication`/',
        );
        $island->Authentication->getResult();
    }

    public function testThePackageRemainsOptionalUntilThePropertyIsAccessed(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Fixture/Authentication/without-package.php']);
        $process->mustRun();
        $this->assertSame('Install cakephp/authentication before using AuthenticationTrait.', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }
}
