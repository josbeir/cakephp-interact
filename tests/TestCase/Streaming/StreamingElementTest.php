<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Streaming;

use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Commerce\Island\CrossPluginIsland;
use Interact\Discovery\Definition;
use Interact\Rendering\PhpRenderer;
use Interact\Test\Fixture\Rendering\App\Island\Article\EditorIsland;
use Interact\View\TemplateView;
use PHPUnit\Framework\TestCase;

/**
 * Verifies streamed native elements use class ownership and CakePHP's configured view cascade.
 *
 * Host view variables and lookup settings remain separate from each streamed fragment.
 */
class StreamingElementTest extends TestCase
{
    protected mixed $previousPaths;

    protected string $fixtures;

    /**
     * Load a real plugin whose island explicitly renders another plugin's template.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = dirname(__DIR__, 2) . '/Fixture/Rendering/';
        $this->previousPaths = Configure::read('App.paths.templates');
        Configure::write('App.paths.templates', [$this->fixtures . 'templates/']);
        foreach (['Commerce' => 'Commerce', 'Acme/Catalog' => 'Catalog'] as $name => $directory) {
            Plugin::getCollection()->add(new BasePlugin([
                'name' => $name,
                'path' => $this->fixtures . 'Plugins/' . $directory . '/',
            ]));
        }
    }

    /**
     * Restore application paths and remove the test plugin's ownership declaration.
     */
    protected function tearDown(): void
    {
        Plugin::getCollection()->remove('Commerce');
        Plugin::getCollection()->remove('Acme/Catalog');
        Configure::write('App.paths.templates', $this->previousPaths);
        parent::tearDown();
    }

    public function testRelativeElementsUseTheClassPluginAndRestoreHostScope(): void
    {
        $view = new TemplateView();
        $view->setPlugin('Commerce')->setTemplatePath('Host')->setSubDir('Admin');
        $view->set('count', 99);
        $renderer = new PhpRenderer($view);
        $definition = new Definition(CrossPluginIsland::class);
        static::assertSame('Acme/Catalog.island/product_card', $definition->declaration->template);
        static::assertStringContainsString('Plugin element: 7', $renderer->element($definition, 'card', [
            'count' => 7,
        ]));
        static::assertStringContainsString('Commerce marker', $renderer->element($definition, 'marker', []));
        static::assertStringContainsString('Application marker', $renderer->element(
            new Definition(EditorIsland::class),
            'marker',
            [],
        ));
        static::assertStringContainsString('Commerce marker', $renderer->element(
            new Definition(EditorIsland::class),
            'Commerce.marker',
            [],
        ));
        static::assertSame('Commerce', $view->getPlugin());
        static::assertSame('Host', $view->getTemplatePath());
        static::assertSame('Admin', $view->getSubDir());
        static::assertSame(99, $view->get('count'));
    }

    public function testStreamedElementsHonorApplicationOverrides(): void
    {
        Configure::write('App.paths.templates', [
            $this->fixtures . 'overrides/',
            $this->fixtures . 'templates/',
        ]);
        $renderer = new PhpRenderer(new TemplateView());
        static::assertStringContainsString('Application element override: 8', $renderer->element(
            new Definition(CrossPluginIsland::class),
            'card',
            ['count' => 8],
        ));
    }
}
