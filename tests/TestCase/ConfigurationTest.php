<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Container\Container;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\View\View;
use Interact\Composition\ModelOptions;
use Interact\Configuration;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateHandler;
use Interact\InteractPlugin;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Runtime\Factory;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\ConfigurationView;
use Interact\Test\Fixture\Island\ConfigurationIsland;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadServices;
use Interact\View\Helper\InteractHelper;
use Interact\View\IslandView;
use Interact\View\TemplateView;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies application settings at signing, container, asset, and native view boundaries.
 * Browser timing remains installation-owned JavaScript and never enters PHP configuration.
 */
class ConfigurationTest extends TestCase
{
    protected mixed $previousSettings;

    protected mixed $previousPaths;

    protected mixed $previousNamespace;

    /**
     * Isolate optional configuration and native template lookup between tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousSettings = Configure::read('Interact');
        $this->previousPaths = Configure::read('App.paths.templates');
        $this->previousNamespace = Configure::read('App.namespace');
        Configure::delete('Interact');
        Configure::write('App.paths.templates', [dirname(__DIR__) . '/Fixture/views/templates/']);
    }

    /**
     * Restore application-owned configuration after every success or rejection.
     */
    protected function tearDown(): void
    {
        Configure::delete('Interact');
        if ($this->previousSettings !== null) {
            Configure::write('Interact', $this->previousSettings);
        }

        Configure::write('App.paths.templates', $this->previousPaths);
        Configure::write('App.namespace', $this->previousNamespace);
        parent::tearDown();
    }

    public function testDefaultsAndExplicitSigningSettings(): void
    {
        $defaults = Configuration::read();
        static::assertSame(1800, $defaults->snapshotTtl);
        static::assertSame(TemplateView::class, $defaults->viewClass);
        static::assertSame('Interact.runtime', $defaults->script);
        static::assertSame(['block' => 'script', 'defer' => true], $defaults->scriptOptions);
        $defaultToken = $defaults->snapshots()->seal(['count' => 1], 'session', 'default');
        static::assertSame(['count' => 1], $defaults->snapshots()->open($defaultToken, 'session', 'default'));

        Configure::write('Interact.snapshots', ['ttl' => 1, 'key' => str_repeat('k', 32)]);
        $settings = Configuration::read();
        $token = $settings->snapshots()->seal(['count' => 2], 'session', 'default');
        static::assertSame(1, $settings->snapshotTtl);
        static::assertSame(['count' => 2], new SnapshotCodec(str_repeat('k', 32))->open($token, 'session', 'default'));
        $expired = new SnapshotCodec(str_repeat('k', 32), clock: static fn(): int => time() + 2);
        $this->expectException(InvalidSnapshotException::class);
        $expired->open($token, 'session', 'default');
    }

    /**
     * Supply invalid application options without allowing implicit coercion or helper registration.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSettings(): iterable
    {
        foreach ([
            'snapshots record' => ['snapshots' => null],
            'unknown snapshot' => ['snapshots' => ['lifetime' => 10]],
            'zero ttl' => ['snapshots' => ['ttl' => 0]],
            'null ttl' => ['snapshots' => ['ttl' => null]],
            'string ttl' => ['snapshots' => ['ttl' => '30']],
            'short key' => ['snapshots' => ['key' => 'short']],
            'non-string key' => ['snapshots' => ['key' => false]],
            'view helpers' => ['view' => ['helpers' => []]],
            'ordinary view' => ['view' => ['className' => View::class]],
            'missing view' => ['view' => ['className' => 'MissingView']],
            'null view' => ['view' => ['className' => null]],
            'numeric view' => ['view' => ['className' => 42]],
            'invalid assets' => ['assets' => false],
            'unknown asset' => ['assets' => ['url' => '/runtime.js']],
            'empty script' => ['assets' => ['script' => '']],
            'null script' => ['assets' => ['script' => null]],
            'true script' => ['assets' => ['script' => true]],
            'null attributes' => ['assets' => ['scriptOptions' => null]],
            'numeric attributes' => ['assets' => ['scriptOptions' => ['value']]],
            'empty block' => ['assets' => ['scriptOptions' => ['block' => '']]],
            'false block' => ['assets' => ['scriptOptions' => ['block' => false]]],
            'invalid debug group' => ['debug' => false],
            'unknown debug option' => ['debug' => ['console' => true]],
            'null debug enabled' => ['debug' => ['enabled' => null]],
            'string debug enabled' => ['debug' => ['enabled' => 'true']],
            'null debug viewer' => ['debug' => ['viewer' => null]],
            'numeric debug viewer' => ['debug' => ['viewer' => 1]],
            'null block' => ['assets' => ['scriptOptions' => ['block' => null]]],
        ] as $name => $options) {
            yield $name => [$options];
        }
    }

    /**
     * Reject malformed new settings before services perform work.
     *
     * @param array<string, mixed> $options Invalid application settings.
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsFailClearly(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration($options);
    }

    public function testInvalidRootConfigurationIsRejected(): void
    {
        Configure::write('Interact', false);
        $this->expectException(InvalidArgumentException::class);
        Configuration::read();
    }

    public function testInvalidUploadConfigurationIsRejectedAtServiceConstruction(): void
    {
        Configure::write('Interact.uploads', false);
        $container = new Container();
        new UploadServices()->register($container);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload configuration must be an array.');
        $container->get(UploadConfig::class);
    }

    public function testDisabledAssetsAndExistingHelperOptOutPreserveDocumentMetadata(): void
    {
        Configure::write('Interact.assets.script', false);
        $view = new View();
        new InteractHelper($view);
        static::assertStringNotContainsString('<script', $view->fetch('script'));
        static::assertStringContainsString('interact:navigation', $view->fetch('script'));
        Configure::delete('Interact');
        $view = new View();
        new InteractHelper($view, ['autoScript' => false, 'document' => false]);
        static::assertSame('', $view->fetch('script'));
    }

    public function testNativeAssetOptionsNonceEscapingAndDeduplication(): void
    {
        Configure::write('Interact.assets', [
            'script' => 'https://assets.example/runtime.js',
            'scriptOptions' => ['block' => 'assets', 'integrity' => 'sha256-test', 'crossorigin' => 'anonymous'],
        ]);
        $view = new View(new ServerRequest()->withAttribute('cspScriptNonce', 'nonce"value'));
        new InteractHelper($view);
        new InteractHelper($view, ['document' => false]);
        $scripts = $view->fetch('assets');
        static::assertSame('', $view->fetch('script'));
        static::assertSame(1, substr_count($scripts, '<script'));
        static::assertStringContainsString('https://assets.example/runtime.js', $scripts);
        static::assertStringContainsString('nonce="nonce&quot;value"', $scripts);
        static::assertStringContainsString('integrity="sha256-test"', $scripts);
        static::assertStringContainsString('defer="defer"', $scripts);
        static::assertSame(
            'script',
            new Configuration(['assets' => ['scriptOptions' => ['block' => true]]])->scriptBlock,
        );
    }

    public function testPluginServicesUseConfigurationAndRespectAnApplicationSigner(): void
    {
        Configure::write('Interact.snapshots', ['ttl' => 42, 'key' => str_repeat('s', 32)]);
        $container = new Container();
        $plugin = new InteractPlugin();
        $plugin->services($container);
        /** @var mixed $codec */
        $codec = $container->get(SnapshotCodec::class);
        static::assertInstanceOf(SnapshotCodec::class, $codec);
        $token = $codec->seal(['configured' => true], 'session', 'default');
        static::assertSame(
            ['configured' => true],
            new SnapshotCodec(str_repeat('s', 32))->open($token, 'session', 'default'),
        );
        static::assertInstanceOf(Factory::class, $container->get(Factory::class));
        $custom = new SnapshotCodec(str_repeat('c', 32));
        $replacement = new Container();
        $replacement->addShared(SnapshotCodec::class, $custom);

        $plugin->services($replacement);
        static::assertSame($custom, $replacement->get(SnapshotCodec::class));
    }

    public function testOnlyThePluginRegistersTheEndpointAndKeepsApplicationReplacements(): void
    {
        $container = new Container();
        new UploadServices()->register($container);
        static::assertFalse($container->hasDefinition(UpdateHandler::class));
        new InteractPlugin()->services($container);
        static::assertNotSame($container->get(UpdateHandler::class), $container->get(UpdateHandler::class));
        $custom = new UpdateHandler(
            new Factory(new Registry(), new SnapshotCodec(str_repeat('h', 32))),
            new FailureResponder(),
        );
        $replacement = new Container();
        $replacement->addShared(UpdateHandler::class, $custom);
        new InteractPlugin()->services($replacement);
        static::assertSame($custom, $replacement->get(UpdateHandler::class));
    }

    public function testConfiguredViewHelpersRenderMountUpdatePlaceholderAndFallback(): void
    {
        Configure::write('Interact', [
            'view' => ['className' => ConfigurationView::class],
            'snapshots' => ['key' => str_repeat('v', 32)],
            'assets' => ['script' => false],
        ]);
        Configure::write('App.namespace', 'Interact\\Test\\Fixture');
        $settings = Configuration::read();
        $request = new ServerRequest(['session' => new Session(['defaults' => 'php'])]);
        $context = new IslandContext($request, 'default', '/_interact/update');
        $factory = new Factory(new Registry(), $settings->snapshots(), configuration: $settings);
        $host = new IslandView($request);
        $host->set('privateHostValue', 'never transported');

        $runtime = $factory->create($host, $context);
        $initial = $runtime->mount(ConfigurationIsland::class, [], 'configured', $context);
        static::assertStringContainsString('Configured view: 0', $initial->html);
        static::assertStringNotContainsString('never transported', $initial->html);
        $updated = $factory->create(new TemplateView($request), $context)->update([
            'snapshot' => $initial->snapshot,
            'action' => 'increment',
        ], $context);
        static::assertStringContainsString('Configured view: 1', $updated->html);
        $pending = $runtime->mount(
            ConfigurationIsland::class,
            [],
            'pending',
            $context,
            new Options('manual', 'configuration_placeholder'),
        );
        static::assertStringContainsString('Configured placeholder', $pending->html);
        $loaded = $runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $context);
        static::assertStringContainsString('Configured view: 0', $loaded->html);
        $view = new ConfigurationView($request);
        $factory->create($view, $context)->mount(ConfigurationIsland::class, [], 'reused', $context);
        static::assertTrue($view->helpers()->has('Interact'));
        $helper = new InteractHelper(new View($request), ['autoScript' => false, 'endpoint' => '/_interact/update']);
        static::assertStringContainsString('Configured view: 0', $helper->render(ConfigurationIsland::class));
    }

    public function testChildDelayOmissionRemainsDistinctFromAnExplicitDelay(): void
    {
        $reader = new ModelOptions();
        static::assertSame(
            ['path' => 'draft', 'timing' => 'live'],
            $reader->read(['path' => 'draft', 'timing' => 'live']),
        );
        static::assertSame(
            ['path' => 'draft', 'timing' => 'live', 'delay' => 250],
            $reader->read(['path' => 'draft', 'timing' => 'live', 'delay' => 250]),
        );
    }
}
