<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Bake;

use Cake\Console\Arguments;
use Cake\Console\ConsoleInput;
use Cake\Console\ConsoleIo;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Interact\Command\Bake\IslandCommand;
use Interact\Test\TestSuite\Bake\BakeTestCase;
use Interact\Test\TestSuite\Bake\ScaffoldCommand;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * Exercises direct and interactive island scaffolding through native Bake templates and console I/O.
 */
class IslandCommandTest extends BakeTestCase
{
    public function testCreatesOnlyAnEmptyClassAndConventionalTemplateWithoutAConnection(): void
    {
        /** @var array<string, mixed> $connection */
        $connection = ConnectionManager::getConfig('default');
        ConnectionManager::drop('default');
        try {
            $this->assertSame(0, $this->generate(['Counter']));
        } finally {
            ConnectionManager::setConfig('default', $connection);
        }

        $class = (string)file_get_contents($this->storage . 'src/Island/CounterIsland.php');
        $template = (string)file_get_contents($this->storage . 'templates/island/counter.php');
        $this->assertStringContainsString('namespace BakedApp\\Island;', $class);
        $this->assertStringContainsString('class CounterIsland extends Island', $class);
        $this->assertStringNotContainsString('function ', $class);
        $this->assertStringNotContainsString('Pagination', $class . $template);
        $this->assertSame([], glob($this->storage . 'tests/*'));
        $this->assertStringContainsString("render('Counter')", $this->output->output());
        $this->assertSame('', $this->errors->output());
        $this->assertSame(APP . 'Island/', new IslandCommand()->getPath(new Arguments([], [], [])));
    }

    public function testPaginationAddsOnlyTheDeclarationAndTemplateAnnotation(): void
    {
        $this->assertSame(0, $this->generate(['Admin/Article/Listing', '--pagination']));
        $class = (string)file_get_contents($this->storage . 'src/Island/Admin/Article/ListingIsland.php');
        $template = (string)file_get_contents($this->storage . 'templates/island/Admin/Article/listing.php');
        $this->assertStringContainsString('namespace BakedApp\\Island\\Admin\\Article;', $class);
        $this->assertStringContainsString("#[Pagination]\n    public PaginationState \$paging;", $class);
        $this->assertStringNotContainsString('function ', $class);
        $this->assertStringContainsString('@var \\Interact\\Pagination\\PaginationState $paging', $template);
        $this->assertStringContainsString("render('Admin/Article/Listing')", $this->output->output());
    }

    public function testInteractiveDefaultsMatchDirectGeneration(): void
    {
        $this->assertSame(0, $this->generate([], ['InteractiveCounter', '']));
        $before = (string)file_get_contents($this->storage . 'src/Island/InteractiveCounterIsland.php');
        $this->assertStringContainsString('Enable pagination?', $this->output->output());
        $this->assertSame(0, $this->generate(['InteractiveCounter']));
        $this->assertSame($before, file_get_contents($this->storage . 'src/Island/InteractiveCounterIsland.php'));
        $this->assertStringContainsString('would not change', $this->output->output());
        $this->assertStringNotContainsString('Enable pagination?', $this->output->output());
    }

    public function testInteractivePaginationUsesNativeChoiceValidation(): void
    {
        $this->assertSame(0, $this->generate([], ['Article/InteractiveListing', 'invalid', 'Y']));
        $class = (string)file_get_contents($this->storage . 'src/Island/Article/InteractiveListingIsland.php');
        $this->assertStringContainsString('#[Pagination]', $class);
        $this->assertSame(2, substr_count($this->output->output(), 'Enable pagination?'));
    }

    public function testExplicitPaginationSkipsTheInteractiveQuestion(): void
    {
        $this->assertSame(0, $this->generate(['--pagination'], ['Article/FlaggedListing']));
        $this->assertStringNotContainsString('Enable pagination?', $this->output->output());
        $this->assertStringContainsString(
            '#[Pagination]',
            (string)file_get_contents($this->storage . 'src/Island/Article/FlaggedListingIsland.php'),
        );
    }

    public function testInvalidInteractiveNamesCanBeCorrectedBeforeGeneration(): void
    {
        $this->assertSame(0, $this->generate([], ['../Wrong', 'Article/Corrected', 'n']));
        $this->assertStringContainsString('Use an island name', $this->errors->output());
        $this->assertSame(2, substr_count($this->output->output(), 'Island name'));
        $this->assertFileExists($this->storage . 'src/Island/Article/CorrectedIsland.php');
    }

    public function testBlankAndClosedInputCancelWithoutCreatingFiles(): void
    {
        $this->assertSame(1, $this->generate([], ['']));
        $this->assertDirectoryDoesNotExist($this->storage . 'src');
        $this->assertSame(1, new ScaffoldCommand($this->storage . 'src/')->run(
            [],
            $this->console(new ConsoleInput('data://text/plain,')),
        ));
        $this->assertDirectoryDoesNotExist($this->storage . 'src');
    }

    public function testQuietModeRequiresANameAndNeverReadsInput(): void
    {
        $this->assertSame(1, $this->generate(['--quiet']));
        $this->assertStringContainsString('An island name is required', $this->errors->output());
        $this->assertDirectoryDoesNotExist($this->storage . 'src');
        $this->assertSame(0, $this->generate(['QuietCounter', '--quiet']));
        $this->assertSame('', $this->output->output());
    }

    /**
     * Cover invalid selectors and conflicting plugin configuration before either file is written.
     *
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'traversal' => [['../Counter'], 'Use an island name'];
        yield 'absolute path' => [['/Counter'], 'Use an island name'];
        yield 'PHP namespace input' => [['Admin\\Counter'], 'Use an island name'];
        yield 'empty suffix' => [['Island'], 'Use an island name'];
        yield 'empty nested suffix' => [['Admin/Island'], 'Use an island name'];
        yield 'unloaded plugin' => [['Missing.Counter'], 'Load the Missing plugin'];
        yield 'conflicting targets' => [['One.Counter', '--plugin', 'Two'], 'must target the same plugin'];
        yield 'plugin traversal' => [['Counter', '--plugin', '../Wrong'], 'Use an island name'];
        yield 'invalid plugin separator' => [
            ['Counter', '--plugin', 'Bad\\Plugin'],
            'Invalid plugin namespace separator',
        ];
    }

    /**
     * Validate direct input without falling back to interactive questions.
     *
     * @param list<string> $arguments Invalid direct arguments.
     */
    #[DataProvider('invalidNames')]
    public function testInvalidNamesFailBeforeWriting(array $arguments, string $message): void
    {
        $this->assertSame(1, $this->generate($arguments));
        $this->assertStringContainsString($message, $this->errors->output());
        $this->assertDirectoryDoesNotExist($this->storage . 'src');
        $this->assertDirectoryDoesNotExist($this->storage . 'templates/island');
    }

    public function testSuffixAndCaseNormalizationMatchRuntimeConventions(): void
    {
        $this->assertSame(0, $this->generate(['admin/article-editorIsland']));
        $this->assertFileExists($this->storage . 'src/Island/Admin/ArticleEditorIsland.php');
        $this->assertFileExists($this->storage . 'templates/island/Admin/article_editor.php');
        $this->assertStringContainsString("render('Admin/ArticleEditor')", $this->output->output());
    }

    public function testPluginTargetsUseNativePathsAndVendorNamespaces(): void
    {
        $path = $this->targetPlugin('Vendor/BakedShop');
        $this->assertSame(0, $this->generate(['Vendor/BakedShop.Admin/Article/Editor']));
        $class = (string)file_get_contents($path . 'src/Island/Admin/Article/EditorIsland.php');
        $this->assertStringContainsString('namespace Vendor\\BakedShop\\Island\\Admin\\Article;', $class);
        $this->assertFileExists($path . 'templates/island/Admin/Article/editor.php');
        $this->assertSame(0, $this->generate(['Admin/Article/Editor', '--plugin', 'Vendor/BakedShop']));
        $this->assertStringContainsString('would not change', $this->output->output());
        $this->assertSame(
            0,
            $this->generate([
                'Vendor/BakedShop.Admin/Article/Editor',
                '--plugin',
                'Vendor/BakedShop',
            ]),
        );
    }

    public function testNativeOverwritePromptsPreserveSkippedFilesAndSupportForce(): void
    {
        $this->assertSame(0, $this->generate(['Overwrite']));
        $this->write('src/Island/OverwriteIsland.php', 'application-owned');
        $this->assertSame(1, $this->generate(['Overwrite'], ['n']));
        $this->assertSame('application-owned', file_get_contents($this->storage . 'src/Island/OverwriteIsland.php'));
        $this->assertStringContainsString('Generation was incomplete', $this->errors->output());
        $this->assertStringNotContainsString('scaffold ready', $this->output->output());
        $this->assertStringNotContainsString('Mount with:', $this->output->output());
        $this->assertSame(0, $this->generate(['Overwrite'], ['y']));
        $this->write('src/Island/OverwriteIsland.php', 'application-owned');
        $this->assertSame(0, $this->generate(['Overwrite', '--force']));
        $this->assertStringContainsString(
            'class OverwriteIsland extends Island',
            (string)file_get_contents($this->storage . 'src/Island/OverwriteIsland.php'),
        );
    }

    public function testWriteFailuresDoNotAnnounceAUsableScaffold(): void
    {
        $io = $this->createMock(ConsoleIo::class);
        $io->expects($this->exactly(2))->method('createFile')->willReturn(false);
        $io->expects($this->once())->method('error')->with('Generation was incomplete. Review the file results above.');
        $io->expects($this->never())->method('success');
        $this->assertSame(1, new ScaffoldCommand($this->storage . 'src/')->run(['Unwritable'], $io));
    }

    public function testApplicationBakeOverridesAndThemesUseNativeLookup(): void
    {
        $this->write('templates/plugin/Interact/bake/Island/template.twig', '<?php // Application override');
        $this->assertSame(0, $this->generate(['Overridden']));
        $this->assertSame(
            '<?php // Application override',
            trim((string)file_get_contents($this->storage . 'templates/island/overridden.php')),
        );
        unlink($this->storage . 'templates/plugin/Interact/bake/Island/template.twig');

        $this->targetPlugin('BakeTheme');
        $this->write('plugins/BakeTheme/templates/bake/.gitkeep', '');
        $this->write(
            'plugins/BakeTheme/templates/plugin/Interact/bake/Island/template.twig',
            '<?php // Theme override',
        );
        $this->assertSame(0, $this->generate(['Themed', '--theme', 'BakeTheme']));
        $this->assertSame(
            '<?php // Theme override',
            trim((string)file_get_contents($this->storage . 'templates/island/themed.php')),
        );
    }

    public function testMissingTemplatePathFailsBeforeWritingEitherFile(): void
    {
        Configure::delete('App.paths.templates');
        $this->assertSame(1, $this->generate(['NoTemplatePath']));
        $this->assertStringContainsString('Could not read template paths', $this->errors->output());
        $this->assertDirectoryDoesNotExist($this->storage . 'src');
    }

    public function testGeneratedPhpPassesSyntaxFormattingAndLintChecks(): void
    {
        foreach (['EmptyScaffold', 'PaginatedScaffold'] as $name) {
            $this->assertSame(0, $this->generate([$name, ...($name === 'PaginatedScaffold' ? ['--pagination'] : [])]));
        }

        $files = [
            ...(glob($this->storage . 'src/Island/*.php') ?: []),
            ...(glob($this->storage . 'templates/island/*.php') ?: []),
        ];
        $this->assertCount(4, $files);
        foreach ($files as $file) {
            $this->assertNotEmpty(PhpToken::tokenize((string)file_get_contents($file), TOKEN_PARSE));
        }

        $repository = dirname(__DIR__, 3);
        foreach (['lint', 'format'] as $check) {
            $process = new Process([
                $repository . '/vendor/bin/mago',
                $check,
                ...($check === 'format' ? ['--check'] : []),
                ...$files,
            ], $repository);
            $this->assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        }
    }
}
