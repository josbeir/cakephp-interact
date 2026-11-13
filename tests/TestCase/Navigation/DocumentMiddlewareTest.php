<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Navigation;

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use Interact\Configuration;
use Interact\Navigation\DocumentMiddleware;
use Interact\View\Helper\InteractHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Checks document compatibility against the helper's effective runtime ownership.
 *
 * Application-owned bundles must not depend on an unused packaged asset. The
 * normal helper and middleware exchange is exercised without modifying that asset.
 */
class DocumentMiddlewareTest extends TestCase
{
    /**
     * Cover global asset configuration and helper overrides through the same response boundary.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function integrationModes(): iterable
    {
        yield 'default standalone' => [[], [], '1:bundle'];
        yield 'explicit standalone extension' => [['assets' => ['script' => 'Interact.runtime.js']], [], '1:bundle'];
        yield 'global opt out' => [['assets' => ['script' => false]], [], '1:application'];
        yield 'helper opt out' => [[], ['autoScript' => false], '1:application'];
        yield 'local application bundle' => [['assets' => ['script' => '/js/app.abc.js']], [], '1:application'];
        yield 'remote application bundle' => [
            ['assets' => ['script' => 'https://assets.example/app.js']],
            [],
            '1:application',
        ];
        yield 'custom metadata block with helper opt out' => [
            ['assets' => ['scriptOptions' => ['block' => 'assets']]],
            ['autoScript' => false],
            '1:application',
        ];
    }

    /**
     * Never read the packaged runtime when its inclusion is disabled or replaced.
     *
     * @param array<string, mixed> $settings Application settings.
     * @param array<string, mixed> $options Helper settings.
     */
    #[DataProvider('integrationModes')]
    public function testOnlyTheSelectedStandaloneAssetUsesItsFingerprint(
        array $settings,
        array $options,
        string $version,
    ): void {
        Configure::write('Interact', $settings);
        $middleware = $this->getMockBuilder(DocumentMiddleware::class)
            ->onlyMethods(['bundledVersion'])
            ->getMock();
        $middleware->expects($version === '1:bundle' ? $this->once() : $this->never())
            ->method('bundledVersion')
            ->willReturn('1:bundle');

        $identity = $this->identity($this->page($middleware, $options));
        $this->assertSame($version, $identity['version']);
        $this->assertSame('', $identity['application']);
    }

    public function testTheDefaultAssetUsesTheActualPackagedRuntimeHash(): void
    {
        Configure::delete('Interact');
        $identity = $this->identity($this->page(new DocumentMiddleware()));
        $hash = hash_file('sha256', dirname(__DIR__, 3) . '/webroot/js/runtime.js');
        $this->assertIsString($hash);
        $this->assertSame('1:' . $hash, $identity['version']);
    }

    public function testApplicationBuildVersionsRemainIndependentOfThePackagedRuntime(): void
    {
        Configure::write('Interact.assets.script', false);
        Configure::write('Interact.navigationVersion', 'app-build-1');
        $middleware = $this->getMockBuilder(DocumentMiddleware::class)
            ->onlyMethods(['bundledVersion'])
            ->getMock();
        $middleware->expects($this->never())->method('bundledVersion');
        $before = $this->identity($this->page($middleware));
        Configure::write('Interact.navigationVersion', 'app-build-2');
        $after = $this->identity($this->page($middleware));
        $this->assertSame('1:application', $before['version']);
        $this->assertSame($before['version'], $after['version']);
        $this->assertSame('app-build-1', $before['application']);
        $this->assertSame('app-build-2', $after['application']);
    }

    /**
     * Pass a helper-generated document through the actual middleware response handling.
     *
     * @param array<string, mixed> $options Helper settings.
     */
    protected function page(DocumentMiddleware $middleware, array $options = []): ResponseInterface
    {
        $request = new ServerRequest([
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);
        $view = new View($request);
        new InteractHelper($view, $options);
        $response = new Response([
            'type' => 'html',
            'body' =>
                '<!doctype html><html><head>'
                    . $view->fetch(Configuration::read()->scriptBlock)
                    . '</head><body></body></html>',
        ]);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $middleware->process($request, $handler);
    }

    /**
     * Decode the final metadata without depending on HTML attribute escaping or ordering.
     *
     * @return array{version: string, application: string, session: string, policy: string}
     */
    protected function identity(ResponseInterface $response): array
    {
        $document = HTMLDocument::createFromString((string)$response->getBody());
        $markers = new XPath($document)->query('//*[@name="interact-navigation"]');
        $this->assertSame(1, $markers->length);
        $marker = $markers->item(0);
        $this->assertInstanceOf(Element::class, $marker);
        /** @var array{version: string, application: string, session: string, policy: string} $identity */
        $identity = json_decode((string)$marker->getAttribute('content'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($identity);
        $this->assertStringNotContainsString('<!--interact:navigation', (string)$response->getBody());

        return $identity;
    }
}
