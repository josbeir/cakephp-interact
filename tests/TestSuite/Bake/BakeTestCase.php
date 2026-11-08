<?php
declare(strict_types=1);

namespace Interact\Test\TestSuite\Bake;

use Bake\BakePlugin;
use Cake\Console\ConsoleInput;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\TestSuite\TestCase;
use Cake\TwigView\TwigViewPlugin;
use Cake\Utility\Filesystem;
use Composer\Autoload\ClassLoader;

/**
 * Runs native Bake rendering and console input against disposable application and plugin paths.
 *
 * Generated classes may be mounted through a temporary PSR-4 loader; the demo and
 * committed fixture trees are never generation destinations.
 */
abstract class BakeTestCase extends TestCase
{
    protected string $storage;

    protected StubConsoleOutput $output;

    protected StubConsoleOutput $errors;

    protected ClassLoader $loader;

    /**
     * Load optional development plugins and isolate every generated file.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/interact-bake-' . bin2hex(random_bytes(8)) . '/';
        new Filesystem()->mkdir($this->storage . 'templates/');
        Configure::write('App.namespace', 'BakedApp');
        Configure::write('App.paths.templates', [$this->storage . 'templates/']);
        Plugin::getCollection()->add(new BakePlugin());
        Plugin::getCollection()->add(new TwigViewPlugin());
        $this->loader = new ClassLoader();
        $this->loader->addPsr4('BakedApp\\', $this->storage . 'src/');
        $this->loader->register(true);

        $this->output = new StubConsoleOutput();
        $this->errors = new StubConsoleOutput();
    }

    /**
     * Release the temporary autoloader and generated files before restoring CakePHP globals.
     */
    protected function tearDown(): void
    {
        $this->loader->unregister();
        new Filesystem()->deleteDir($this->storage);
        parent::tearDown();
    }

    /**
     * Execute the real option parser and file writer with controlled console answers.
     *
     * @param list<string> $arguments Command arguments, without the cake/bake/island prefix.
     * @param list<string> $answers Native prompt replies.
     */
    protected function generate(array $arguments, array $answers = []): ?int
    {
        $this->output->clear();
        $this->errors->clear();

        return new ScaffoldCommand($this->storage . 'src/')->run(
            $arguments,
            $this->console(new StubConsoleInput($answers)),
        );
    }

    /**
     * Capture native console feedback without replacing Bake's prompts or file handling.
     */
    protected function console(ConsoleInput $input): ConsoleIo
    {
        return new ConsoleIo($this->output, $this->errors, $input);
    }

    /**
     * Register an application-owned plugin with a real disposable native directory layout.
     */
    protected function targetPlugin(string $name = 'BakedShop'): string
    {
        $path = $this->storage . 'plugins/' . $name . '/';
        new Filesystem()->mkdir($path . 'src/');
        Plugin::getCollection()->add(new BasePlugin(['name' => $name, 'path' => $path]));
        $this->loader->addPsr4(str_replace('/', '\\', $name) . '\\', $path . 'src/');

        return $path;
    }

    /**
     * Create a native Bake override or sentinel file with its parent directories.
     */
    protected function write(string $relative, string $contents): void
    {
        new Filesystem()->mkdir(dirname($this->storage . $relative));
        file_put_contents($this->storage . $relative, $contents);
    }
}
