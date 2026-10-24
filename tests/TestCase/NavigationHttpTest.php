<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Core\Configure;
use Cake\Error\Renderer\WebExceptionRenderer;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use Interact\Navigation\DocumentMiddleware;
use Interact\Test\TestSuite\HttpTestCase;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exercises document policy fingerprints and persistent mounts through real CakePHP requests.
 * Fresh destination authorization and action-only navigation remain server responsibilities.
 */
class NavigationHttpTest extends HttpTestCase
{
    public function testOrdinaryDestinationsHaveCompatibleDocumentsAndDistinctAuthorizedMounts(): void
    {
        $first = $this->mounted(url: '/navigation');
        $before = $this->metadata();
        $this->assertResponseContains('x-interact:navigate.hover');
        $this->assertResponseContains('x-interact:persist="notebook"');
        $document = HTMLDocument::createFromString((string)$this->response?->getBody());
        $mount = new XPath($document)->query('//*[@data-interact-mount]')
            ->item(0);
        static::assertInstanceOf(Element::class, $mount);
        $fingerprint = $mount->getAttribute('data-interact-mount');
        static::assertIsString($fingerprint);
        $second = $this->mounted(url: '/navigation/details');
        static::assertNotSame($first['id'], $second['id']);
        static::assertSame($before, $this->metadata());
        $this->assertResponseContains('data-interact-mount="' . $fingerprint . '"');
        $this->mounted(url: '/navigation/details?edition=new');
        $this->assertResponseNotContains('data-interact-mount="' . $fingerprint . '"');
    }

    public function testEnhancedActionRedirectAddsOnlyItsExplicitProtocolFlag(): void
    {
        $mount = $this->mounted(url: '/navigation');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'openDetails']);
        $this->assertResponseOk();
        $this->assertResponseContains('"redirect":{"url":"\\/navigation\\/details","replace":false,"navigate":true}');
        $this->assertResponseNotContains('"html"');
        $this->assertResponseNotContains('interact-navigation');
    }

    public function testEveryDestinationAndRetainedSnapshotRequireCurrentPermission(): void
    {
        $mount = $this->mounted(url: '/navigation');
        Configure::write('Error.exceptionRenderer', WebExceptionRenderer::class);
        $this->session(['demo.role' => 'viewer']);
        $this->get('/navigation/details');
        $this->assertResponseCode(403);
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'remember']);
        $this->assertResponseCode(403);
    }

    public function testApplicationVersionAndPoliciesAreComparedWithoutExposingSessionIds(): void
    {
        $this->mounted(url: '/navigation');
        $before = $this->metadata();
        Configure::write('Interact.navigationVersion', 'deployment-2');
        try {
            $this->mounted(url: '/navigation/details');
            $after = $this->metadata();
            static::assertSame('deployment-2', $after['application']);
            static::assertSame($before['policy'], $after['policy']);
            static::assertSame($before['session'], $after['session']);
            static::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $after['session']);
        } finally {
            Configure::delete('Interact.navigationVersion');
        }
    }

    public function testMiddlewarePreservesNonDocumentsAndMarksFinalPolicy(): void
    {
        $request = new ServerRequest([
            'url' => '/navigation',
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);
        $document = new Response([
            'type' => 'html',
            'body' => '<html><head>' . DocumentMiddleware::PLACEHOLDER . '</head></html>',
        ])->withHeader('ETag', 'original-body');
        $middleware = new DocumentMiddleware();
        $handler = $this->createStub(RequestHandlerInterface::class);
        foreach ([
            new Response(['type' => 'json', 'body' => DocumentMiddleware::PLACEHOLDER]),
            $document->withStatus(404),
            $document->withHeader('Content-Encoding', 'gzip'),
            $document->withHeader('Content-Disposition', 'attachment'),
            new Response(['type' => 'html', 'body' => '<p>No helper marker</p>']),
        ] as $response) {
            $handler->method('handle')->willReturn($response);
            static::assertSame($response, $middleware->process($request, $handler));
            $handler = $this->createStub(RequestHandlerInterface::class);
        }

        $handler->method('handle')->willReturn($document);
        static::assertSame($document, $middleware->process($request->withMethod('POST'), $handler));
        $first = $middleware->process($request, $handler);
        static::assertStringContainsString('interact-navigation', (string)$first->getBody());
        static::assertFalse($first->hasHeader('ETag'));
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($document->withHeader('Content-Security-Policy', "script-src 'self'"));
        $second = $middleware->process($request, $handler);
        static::assertNotSame((string)$first->getBody(), (string)$second->getBody());
    }

    public function testRotatingNoncesUseNativeHiddenMarkersAndStableCompatibility(): void
    {
        $this->mounted(url: '/navigation/nonces');
        $first = $this->metadata();
        $before = (string)$this->response?->getBody();
        $header = $this->response?->getHeaderLine('Content-Security-Policy');
        $this->mounted(url: '/navigation/nonces/details');
        static::assertSame($first, $this->metadata());
        static::assertNotSame($header, $this->response?->getHeaderLine('Content-Security-Policy'));
        static::assertNotSame($before, (string)$this->response?->getBody());
        $document = HTMLDocument::createFromString((string)$this->response?->getBody());
        $markers = new XPath($document)->query('//*[@name="interact-navigation-nonce"]');
        static::assertSame(2, $markers->length);
        foreach ($markers as $index => $marker) {
            static::assertInstanceOf(Element::class, $marker);
            $nonce = $marker->getAttribute('nonce');
            static::assertNotEmpty($nonce);
            static::assertSame((string)$index, $marker->getAttribute('content'));
            static::assertStringContainsString(
                "'nonce-" . (string)$nonce . "'",
                (string)$this->response?->getHeaderLine('Content-Security-Policy'),
            );
            static::assertStringNotContainsString((string)$nonce, json_encode($first, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Read the runtime-owned document envelope without relying on attribute ordering.
     *
     * @return array{version: string, application: string, session: string, policy: string, nonceCount?: int}
     */
    protected function metadata(): array
    {
        $document = HTMLDocument::createFromString((string)$this->response?->getBody());
        $nodes = new XPath($document)->query('//*[@name="interact-navigation"]');
        static::assertSame(1, $nodes->length);
        $node = $nodes->item(0);
        static::assertInstanceOf(Element::class, $node);
        /** @var array{version: string, application: string, session: string, policy: string, nonceCount?: int} $value */
        $value = json_decode((string)$node->getAttribute('content'), true, flags: JSON_THROW_ON_ERROR);
        static::assertIsArray($value);

        return $value;
    }
}
