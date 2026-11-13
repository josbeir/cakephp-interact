<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\TestSuite;

use Cake\Core\Configure;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\UploadedFile;
use Cake\TestSuite\TestCase;
use Closure;
use Interact\Loading\Options;
use Interact\Test\Fixture\TestSuite\Application;
use Interact\Test\Fixture\TestSuite\ProbeService;
use Interact\TestSuite\IslandTest;
use Interact\TestSuite\IslandTestTrait;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercises pending test mounts without simulating browser visibility or scheduling.
 *
 * Real placeholders and signed initialization preserve authorization and defer
 * application mount and rendering work until the test explicitly synchronizes.
 */
class IslandLoadingTest extends TestCase
{
    use IslandTestTrait;

    protected ProbeService $probe;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.paths.templates', [
            APP . 'templates/',
            dirname(__DIR__, 2) . '/Fixture/TestSuite/templates/',
        ]);
        $this->configApplication(Application::class, [CONFIG]);
        $this->probe = new ProbeService();
        $this->mockService(ProbeService::class, fn(): ProbeService => $this->probe);
    }

    /**
     * Supply each non-eager mount trigger; browser timing stays outside PHP tests.
     *
     * @return iterable<string, array{string}> Supported deferred loading modes.
     */
    public static function modes(): iterable
    {
        yield 'viewport' => ['lazy'];
        yield 'after load' => ['defer'];
        yield 'explicit' => ['manual'];
    }

    #[DataProvider('modes')]
    public function testMountWorkWaitsForSyncAndSubsequentSyncsNeverRemount(string $mode): void
    {
        $test = $this->testIsland('Testing', ['label' => 'Pending report'], loading: $mode)->assertPending();
        $id = $test->result()->id;
        $this->assertSame([], $test->result()->state);
        $rendersBeforeInitialization = $this->probe->renders;
        $this->assertSame(0, $rendersBeforeInitialization);
        $this->assertCount(1, $this->probe->requests);
        $this->assertStringContainsString('data-interact-loading="' . $mode . '"', $test->result()->html);
        $test->sync()->assertInitialized()->assertSet('count', 5)->assertSee('Pending report');
        $this->assertSame($id, $test->result()->id);
        $this->assertTrue($this->probe->requests[1]->getData('initialize'));
        $this->assertSame('POST', $this->probe->requests[1]->getMethod());
        $test->call('record', 2)->sync()->assertSet('count', 7);
        $this->assertNull($this->probe->requests[3]->getData('initialize'));
        $this->assertSame(3, $this->probe->renders);
    }

    public function testNativePlaceholderElementsReceiveDeclaredProps(): void
    {
        $this->testIsland('Testing', ['label' => '<Report>'], loading: new Options('manual', 'TestSuite/pending'))
            ->assertPending()
            ->assertSee('Waiting for <Report>')
            ->sync()
            ->assertInitialized()
            ->assertSee('<Report>');
    }

    public function testPendingMountsStillRequireInitialAuthorization(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->testIsland('Testing', request: new ServerRequest()->withAttribute('allowed', false), loading: 'lazy');
    }

    public function testFailedInitializationRetainsThePendingTicketAndRechecksAccess(): void
    {
        $request = new ServerRequest(['url' => '/playground']);
        $test = $this->testIsland('Testing', request: $request, loading: 'defer');
        $snapshot = $test->result()->snapshot;
        try {
            $test->withRequest($request->withAttribute('allowed', false))->sync();
            $this->fail('Current access must be checked before deferred mount work.');
        } catch (ForbiddenException) {
            $test->assertPending();
            $this->assertSame($snapshot, $test->result()->snapshot);
            $this->assertSame(0, $this->probe->renders);
        }

        $test->withRequest($request)->sync()->assertInitialized()->assertSet('count', 5);
    }

    /**
     * Supply operations that must not implicitly initialize an island or bypass its ticket.
     *
     * @return iterable<string, array{Closure(IslandTest): IslandTest}> Forbidden pending interactions.
     */
    public static function pendingOperations(): iterable
    {
        yield 'edit' => [static fn(IslandTest $test): IslandTest => $test->set('title', 'Premature')];
        yield 'action' => [static fn(IslandTest $test): IslandTest => $test->call('record')];
        yield 'stage' => [
            static fn(IslandTest $test): IslandTest => $test->upload(
                'document',
                new UploadedFile('php://temp', 0, UPLOAD_ERR_OK, 'file.txt'),
            ),
        ];
        yield 'remove' => [static fn(IslandTest $test): IslandTest => $test->removeUpload('document')];
    }

    /**
     * Reject ordinary operations until the test intentionally initializes its mount.
     *
     * @param Closure(IslandTest): IslandTest $operation Pending operation.
     */
    #[DataProvider('pendingOperations')]
    public function testPendingMountsRequireExplicitInitialization(Closure $operation): void
    {
        $test = $this->testIsland('Testing', loading: 'manual');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sync\(\)/');
        $operation($test);
    }

    public function testEagerMountsAreInitializedImmediately(): void
    {
        $test = $this->testIsland('Testing')->assertInitialized();
        $this->expectException(AssertionFailedError::class);
        $test->assertPending();
    }

    public function testPendingMountsFailInitializedAssertions(): void
    {
        $test = $this->testIsland('Testing', loading: 'lazy');
        $this->expectException(AssertionFailedError::class);
        $test->assertInitialized();
    }

    public function testInvalidModesUseTheNormalLoadingValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->testIsland('Testing', loading: 'later');
    }
}
