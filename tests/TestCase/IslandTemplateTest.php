<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Acme\Catalog\Island\ProductCardIsland as ProductCard;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\View\Exception\MissingTemplateException;
use Commerce\Island\Article\EditorIsland as Editor;
use Commerce\Island\CrossPluginIsland as CrossPlugin;
use Commerce\Island\ExplicitElementIsland as ExplicitElement;
use Commerce\Island\ExplicitTemplateIsland as ExplicitTemplate;
use Commerce\Island\LiteralTemplateIsland as LiteralTemplate;
use Interact\Discovery\Declaration;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateHandler;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Rendering\PhpRenderer;
use Interact\Runtime\Factory;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Island\Article\EditorIsland as LocalEditor;
use Interact\Test\Fixture\Island\CounterIsland;
use Interact\Test\Fixture\UnlocatedIsland;
use Interact\View\TemplateView;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TestApp\Island\CounterIsland as Counter;

/**
 * Exercises inferred native templates, plugin ownership, and CakePHP's override cascade.
 * Real plugin files and signed updates keep class resolution and rendering assertions connected.
 */
class IslandTemplateTest extends TestCase
{
    protected mixed $previousPaths;

    protected string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = dirname(__DIR__) . '/Fixture/';
        $this->previousPaths = Configure::read('App.paths.templates');
        Configure::write('App.paths.templates', [$this->fixtures . 'views/templates/']);
        foreach (['Commerce' => 'Commerce', 'Acme/Catalog' => 'Catalog'] as $name => $directory) {
            Plugin::getCollection()->add(new BasePlugin([
                'name' => $name,
                'path' => $this->fixtures . 'Plugins/' . $directory . '/',
            ]));
        }
    }

    protected function tearDown(): void
    {
        foreach (['Commerce', 'Acme/Catalog', 'PreviewTheme', 'Umbrella'] as $name) {
            Plugin::getCollection()->remove($name);
        }

        Configure::write('App.paths.templates', $this->previousPaths);
        parent::tearDown();
    }

    /**
     * Supply class structures and explicit native rendering targets.
     *
     * @return iterable<string, array{class-string<\Interact\Island>, string, string|null}> Native rendering targets.
     */
    public static function targets(): iterable
    {
        yield 'application subclass' => [LocalEditor::class, 'island/Article/editor', null];
        yield 'custom application namespace' => [Counter::class, 'island/counter', null];
        yield 'class suffix' => [CounterIsland::class, 'island/counter', null];
        yield 'nested plugin' => [Editor::class, 'Commerce.island/Article/editor', 'Commerce'];
        yield 'vendor plugin' => [ProductCard::class, 'Acme/Catalog.island/product_card', 'Acme/Catalog'];
        yield 'explicit template' => [ExplicitTemplate::class, 'Commerce.island/Article/editor', 'Commerce'];
        yield 'explicit element' => [ExplicitElement::class, 'Commerce.card', 'Commerce'];
        yield 'other plugin' => [CrossPlugin::class, 'Acme/Catalog.island/product_card', 'Commerce'];
        yield 'literal dot' => [LiteralTemplate::class, 'Commerce.literal.name', 'Commerce'];
    }

    /**
     * Verify each class's compiled native rendering target.
     *
     * @param class-string<\Interact\Island> $class Island declaration under test.
     */
    #[DataProvider('targets')]
    public function testCompilesClassDerivedAndExplicitTargets(string $class, string $target, ?string $owner): void
    {
        $definition = new Definition($class);
        static::assertSame($owner, $definition->plugin);
        static::assertSame(
            $target,
            $definition->declaration->element ?? $definition->declaration->template,
        );
    }

    public function testInferenceRequiresAnIslandNamespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit template or element');
        new Definition(UnlocatedIsland::class);
    }

    public function testMostSpecificLoadedSourceRootOwnsManualRegistrations(): void
    {
        Plugin::getCollection()->add(new BasePlugin([
            'name' => 'Umbrella',
            'path' => $this->fixtures,
            'classPath' => $this->fixtures . 'Plugins/',
        ]));
        $registry = new Registry();
        $registry->register(Editor::class);
        static::assertSame('Commerce', $registry->definition('Commerce.Article/Editor')->plugin);
    }

    public function testNamesResolveOnDemandWithoutRegistrationOrDiscovery(): void
    {
        $registry = new Registry();
        $editor = $registry->definition('Commerce.article/editor');
        static::assertSame(Editor::class, $editor->class);
        static::assertSame('Commerce', $editor->plugin);
        static::assertSame($editor, $registry->definition('Commerce.Article/Editor'));
        static::assertNotSame($registry->create($editor), $registry->create($editor));
        static::assertSame(ProductCard::class, $registry->definition('Acme/Catalog.product_card')->class);
        static::assertSame('Commerce.island/Article/editor', $editor->declaration->template);
    }

    /**
     * Supply malformed selectors and classes that must not become callable islands.
     *
     * @return iterable<string, array{string}> Invalid public selectors.
     */
    public static function invalidNames(): iterable
    {
        foreach ([
            '',
            '../Counter',
            '/Counter',
            'Article/../Editor',
            Editor::class,
            'Commerce..Article/Editor',
            'UnknownPlugin.Counter',
            'Commerce.Unknown',
            'Commerce.NotAn',
            'Commerce.Abstract',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('invalidNames')]
    public function testRejectsUnboundedNamesAndNonIslandClasses(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Registry()->definition($name);
    }

    public function testRenderCanChangeOnlyTheRootTag(): void
    {
        $definition = new Registry()->definition('Commerce.Tagged');
        static::assertSame('Commerce.island/tagged', $definition->declaration->template);
        static::assertSame('section', $definition->declaration->tag);
    }

    public function testConfiguredApplicationNamespaceDeterminesTheIslandRoot(): void
    {
        $namespace = (string)Configure::read('App.namespace');
        Configure::write('App.namespace', 'Interact\\Test\\Fixture\\Island\\Site');
        try {
            $definition = new Registry()->definition('Editor');
            static::assertSame('island/editor', $definition->declaration->template);
            static::assertSame('Editor', $definition->declaration->name);
        } finally {
            Configure::write('App.namespace', $namespace);
        }
    }

    public function testExplicitNamesRetainClassOwnedViews(): void
    {
        $registry = new Registry();
        $registry->register(Editor::class, 'shop_editor');

        $definition = $registry->definition('ShopEditor');
        static::assertSame(Editor::class, $definition->class);
        static::assertSame('Commerce.island/Article/editor', $definition->declaration->template);
    }

    public function testPluginTemplateAndItsRelativeElementsRenderNatively(): void
    {
        $html = new PhpRenderer(new TemplateView())->render(new Definition(Editor::class), ['count' => 3]);
        static::assertStringContainsString('Plugin editor: 3', $html);
        static::assertStringContainsString('Commerce marker', $html);
        static::assertStringNotContainsString('Application marker', $html);
    }

    public function testApplicationOverridesPluginTemplatesAndElements(): void
    {
        $this->enableOverrides();
        $renderer = new PhpRenderer(new TemplateView());
        static::assertStringContainsString('Application override: 4', $renderer->render(new Definition(Editor::class), [
            'count' => 4,
        ]));
        static::assertStringContainsString('Application element override: 5', $renderer->render(
            new Definition(ExplicitElement::class),
            ['count' => 5],
        ));
        static::assertStringContainsString('Application catalog override: 6', $renderer->render(
            new Definition(CrossPlugin::class),
            ['count' => 6],
        ));
    }

    public function testCrossPluginAndLiteralDotTargetsRetainNativeSemantics(): void
    {
        $renderer = new PhpRenderer(new TemplateView());
        static::assertStringContainsString('Catalog card: 2', $renderer->render(new Definition(CrossPlugin::class), [
            'count' => 2,
        ]));
        static::assertStringContainsString('Literal dot template', $renderer->render(
            new Definition(LiteralTemplate::class),
            [],
        ));
        static::assertStringContainsString('Plugin element: 1', $renderer->render(
            new Definition(ExplicitElement::class),
            ['count' => 1],
        ));
    }

    public function testThemeOverridesTakeNativePrecedence(): void
    {
        $this->enableOverrides();
        Plugin::getCollection()->add(new BasePlugin([
            'name' => 'PreviewTheme',
            'path' => $this->fixtures . 'views/theme/',
        ]));
        $view = new TemplateView(viewOptions: ['theme' => 'PreviewTheme']);
        static::assertStringContainsString('Theme override', new PhpRenderer($view)->render(
            new Definition(Editor::class),
            ['count' => 0],
        ));
    }

    public function testNestedIslandsRestorePluginAndHostViewScope(): void
    {
        $view = $this->hostView();
        $html = new PhpRenderer($view)->render(new Definition(Editor::class), [
            'count' => 2,
            'child' => new Definition(LocalEditor::class)->declaration,
        ]);
        static::assertSame(2, substr_count($html, 'Commerce marker'));
        static::assertStringContainsString('Application marker', $html);
        static::assertStringContainsString('Application editor: 2', $html);
        $this->assertHostScope($view);
    }

    public function testExceptionsRestorePluginAndHostViewScope(): void
    {
        $view = $this->hostView();
        try {
            $view->island(new Declaration('throwing', template: 'island/throwing'), []);
            static::fail('The template must throw.');
        } catch (RuntimeException $runtimeException) {
            static::assertSame('Template failure.', $runtimeException->getMessage());
        }

        $this->assertHostScope($view);
    }

    public function testMissingInferredTemplatesUseTheNativeExceptionAndRestoreScope(): void
    {
        $view = $this->hostView();
        try {
            new PhpRenderer($view)->render(new Definition(CounterIsland::class), []);
            static::fail('The inferred template must be missing.');
        } catch (MissingTemplateException $missingTemplateException) {
            static::assertStringContainsString('island/counter.php', $missingTemplateException->getMessage());
        }

        $this->assertHostScope($view);
    }

    public function testTemplatesCanExtendOtherNativeTemplates(): void
    {
        $html = new TemplateView()->island(new Declaration('extending', template: 'island/extending'), []);
        static::assertStringContainsString('<article>', $html);
        static::assertStringContainsString('Extended island', $html);
    }

    public function testRenderingRequiresCompiledDefaults(): void
    {
        $this->expectException(LogicException::class);
        new TemplateView()->island(new Declaration('uncompiled'), []);
    }

    public function testSignedUpdatesAndDeferredInitializationUseTheSameOverride(): void
    {
        $this->enableOverrides();
        $request = new ServerRequest([
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
            'environment' => ['REQUEST_METHOD' => 'POST'],
            'url' => '/_interact/update',
        ]);
        $context = new IslandContext($request, 'default', '/_interact/update');
        $registry = new Registry();
        $factory = new Factory($registry, new SnapshotCodec(str_repeat('v', 32)));
        $runtime = $factory->create($this->hostView(), $context);
        $initial = $runtime->mount('Commerce.Article/Editor', [], 'editor', $context);
        static::assertStringContainsString('Application override: 0', $initial->html);
        $handler = new UpdateHandler($factory, new FailureResponder());
        $response = $handler->handle($request->withParam('plugin', 'Interact')
            ->withParsedBody([
                'version' => 1,
                'snapshot' => $initial->snapshot,
                'action' => 'increment',
            ]), new Response());
        static::assertSame(200, $response->getStatusCode());
        static::assertStringContainsString('Application override: 1', (string)$response->getBody());
        $pending = $runtime->mount('Commerce.Article/Editor', [], 'deferred', $context, new Options('manual'));
        $initialized = $runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $context);
        static::assertStringContainsString('Application override: 0', $initialized->html);
    }

    /**
     * Enable application-owned files using CakePHP's normal plugin override directory.
     */
    protected function enableOverrides(): void
    {
        Configure::write('App.paths.templates', [
            $this->fixtures . 'views/overrides/',
            $this->fixtures . 'views/templates/',
        ]);
    }

    /**
     * Create unrelated host settings that must never determine island rendering.
     */
    protected function hostView(): TemplateView
    {
        $view = new TemplateView();
        $view->setPlugin('Acme/Catalog')->setTemplatePath('Host')->setSubDir('Admin');
        $view->set('host', 'retained');

        return $view;
    }

    /**
     * Assert that island rendering restored every configured host lookup input.
     */
    protected function assertHostScope(TemplateView $view): void
    {
        static::assertSame('Acme/Catalog', $view->getPlugin());
        static::assertSame('Host', $view->getTemplatePath());
        static::assertSame('Admin', $view->getSubDir());
        static::assertSame('retained', $view->get('host'));
        static::assertNull($view->get('count'));
    }
}
