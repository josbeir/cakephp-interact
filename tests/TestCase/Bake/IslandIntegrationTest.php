<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Bake;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Pagination\PaginationState;
use Interact\Runtime\Factory;
use Interact\Security\SnapshotCodec;
use Interact\Test\TestSuite\Bake\BakeTestCase;
use Interact\View\Helper\InteractHelper;
use Interact\View\TemplateView;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * Verifies native command discovery, optional-dependency isolation, and mounting real generated files.
 */
class IslandIntegrationTest extends BakeTestCase
{
    public function testNativeBakeDiscoveryGeneratesFilesThroughTheSharedTestApplication(): void
    {
        $process = $this->process('with-bake', [
            'bake',
            'island',
            'BakedShop.Admin/Article/Discovered',
            '--pagination',
        ]);
        $this->assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString("render('BakedShop.Admin/Article/Discovered')", $process->getOutput());
        $this->assertFileExists($this->storage . 'src/Island/Admin/Article/DiscoveredIsland.php');
        $this->assertFileExists($this->storage . 'templates/island/Admin/Article/discovered.php');
    }

    public function testHelpListsTheMinimalApiWithoutPromptsOrFiles(): void
    {
        $process = $this->process('with-bake', ['bake', 'island', '--help']);
        $this->assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('--pagination', $process->getOutput());
        $this->assertStringContainsString('Admin/Article/Listing', $process->getOutput());
        $this->assertStringContainsString('Commerce.Article/Editor', $process->getOutput());
        $this->assertStringNotContainsString('Enable pagination?', $process->getOutput());
        $this->assertDirectoryDoesNotExist($this->storage . 'src/Island');
    }

    public function testConsoleBootsAndListsExistingInteractCommandsWithoutBakeInstalled(): void
    {
        $process = $this->process('without-bake', ['--help', '--verbose']);
        $this->assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('cleanup_uploads', $process->getOutput());
        $this->assertStringNotContainsString('bake island', $process->getOutput());
    }

    /**
     * Cover both generated declarations through application and plugin class resolution.
     *
     * @return iterable<string, array{string, list<string>, string|null}>
     */
    public static function mountedScaffolds(): iterable
    {
        yield 'empty application island' => ['Admin/Article/MountedEditor', [], null];
        yield 'paginated application island' => ['Admin/Article/PagedListing', ['--pagination'], null];
        yield 'namespaced plugin island' => ['BakedShop.Admin/Article/MountedEditor', ['--pagination'], 'BakedShop'];
    }

    /**
     * Mount generated PHP rather than relying on output snapshots alone.
     *
     * @param list<string> $options Generation flags.
     */
    #[DataProvider('mountedScaffolds')]
    public function testGeneratedFilesMountWithRuntimeOwnedRootsAndFreshPaging(
        string $name,
        array $options,
        ?string $plugin,
    ): void {
        if ($plugin !== null) {
            $this->targetPlugin($plugin);
        }

        $this->assertSame(0, $this->generate([$name, ...$options]));
        $registry = new Registry();
        $definition = $registry->resolve($name);
        $request = new ServerRequest([
            'url' => '/scaffold',
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);
        $first = $registry->create($definition, $request);
        $second = $registry->create($definition, $request);
        $paginations = $definition->paginations();
        $this->assertCount($options === [] ? 0 : 1, $paginations);
        if (isset($paginations['paging'])) {
            $property = $paginations['paging'];
            $paging = $property->state($first);
            $this->assertInstanceOf(PaginationState::class, $paging);
            $this->assertSame(1, $paging->page);
            $this->assertSame(20, $paging->limit);
            $this->assertNotSame($paging, $property->state($second));
        }

        Configure::write('Security.salt', str_repeat('b', 32));
        $context = new IslandContext($request, 'default', '/_interact/update');
        $view = new TemplateView($request);
        $helper = new InteractHelper($view, ['autoScript' => false, 'document' => false]);
        $codec = new SnapshotCodec(str_repeat('b', 32));
        $helper->attach(new Factory($registry, $codec)->create($view, $context), $context);
        $html = $helper->render($name);
        $this->assertStringContainsString("<!-- Add the island's content here. -->", $html);
        $this->assertStringContainsString('data-interact-id=', $html);
        $this->assertStringContainsString('data-interact-snapshot=', $html);
    }

    /**
     * Boot the shared test application in a subprocess to isolate optional Bake classes and its database.
     *
     * @param list<string> $arguments Cake console arguments.
     */
    protected function process(string $mode, array $arguments): Process
    {
        $repository = dirname(__DIR__, 3);

        return new Process(
            [
                PHP_BINARY,
                $repository . '/tests/TestSuite/Bake/console.php',
                $this->storage,
                $mode,
                ...$arguments,
            ],
            $repository,
            ['INTERACT_DEMO_TMP' => $this->storage . 'runtime/'],
        );
    }
}
