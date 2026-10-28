<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Demo;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use Interact\Test\TestSuite\Http\HttpTestCase;

/**
 * Verifies article listing, nested editor identity, validation, and explicit persistence.
 * Real requests retain the demo application, middleware, and shared isolated HTTP harness.
 */
class ArticleEditingHttpTest extends HttpTestCase
{
    public function testListingPaginationStopsAtTheLastVisiblePageAndHandlesEmptySearches(): void
    {
        $mount = $this->mounted();
        $snapshot = $mount['snapshot'];
        foreach ([2, 3, 3] as $expectedPage) {
            $this->update(['snapshot' => $snapshot, 'action' => 'next']);
            $this->assertResponseOk();
            $this->assertResponseContains('"page":' . (string)$expectedPage);
            $snapshot = $this->nextSnapshot();
        }

        $this->update(['snapshot' => $snapshot, 'updates' => ['search' => 'no matching articles']]);
        $this->assertResponseOk();
        $this->assertResponseContains('No articles here.');
        $this->assertResponseContains('"page":1');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'next']);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":1');
    }

    public function testInitialPageAndNestedIslandsRender(): void
    {
        $this->mounted();
        $this->assertResponseContains('Alpine patterns');
        $this->assertResponseContains('Belgium');
        $this->assertResponseNotContains('Private article');
        $this->assertResponseContains('interact/js/runtime.js');
        $this->assertHeader('Content-Security-Policy', "script-src 'self'; object-src 'none'; base-uri 'self'");
    }

    public function testSearchUpdateDoesNotRerunTheControllerOrLayout(): void
    {
        $mount = $this->mounted();
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['search' => 'CakePHP']]);
        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $body = (string)$this->response?->getBody();
        $this->assertStringContainsString('data-interact-preserve', $body);
        $this->assertStringContainsString('CakePHP', $body);
        $this->assertStringNotContainsString('<!doctype', $body);
    }

    public function testParentRefreshPreservesChildIdentity(): void
    {
        $mount = $this->mounted();
        $this->update(['snapshot' => $mount['snapshot']]);
        $this->assertResponseOk();
        $this->assertResponseContains('data-interact-preserve');
    }

    public function testValidationAndPersistenceUseTheNormalModelLifecycle(): void
    {
        $mount = $this->mounted(1);
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['title' => ''], 'action' => 'save']);
        $this->assertResponseCode(422);
        $this->assertHeader('Interact', 'true');
        $this->assertResponseContains('A title is required.');
        /** @var mixed $payload */
        $payload = json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame(['title' => ['A title is required.']], $payload['errors']);
        $this->assertSame([], $payload['events']);
        $this->assertIsString($payload['html']);
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $payload['html'] . '</body></html>');
        $input = new XPath($document)->query('//*[@name="title"]')
            ->item(0);
        $this->assertInstanceOf(Element::class, $input);
        $this->assertSame('', $input->getAttribute('value'));
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $error = $document->getElementById((string)$input->getAttribute('aria-describedby'));
        $this->assertInstanceOf(Element::class, $error);
        $this->assertSame('alert', $error->getAttribute('role'));
        $this->assertSame('A title is required.', $error->textContent);
        $title = '<script>' . str_repeat('x', 101) . '</script>';
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['title' => $title], 'action' => 'save']);
        $this->assertResponseCode(422);
        /** @var mixed $payload */
        $payload = json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['state']);
        $this->assertSame(['articleId' => 1, 'title' => $title, 'saved' => false], $payload['state']);
        $this->assertIsString($payload['html']);
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $payload['html'] . '</body></html>');
        $input = new XPath($document)->query('//*[@name="title"]')
            ->item(0);
        $this->assertInstanceOf(Element::class, $input);
        $this->assertSame($title, $input->getAttribute('value'));
        $this->assertStringNotContainsString('<script>', $payload['html']);
        $this->assertStringContainsString('&lt;script&gt;', $payload['html']);
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'updates' => ['title' => 'Alpine patterns'],
            'action' => 'save',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('article-saved');
        $this->assertResponseContains('"errors":{}');
        $this->assertResponseNotContains('aria-invalid');
        $this->assertResponseNotContains('oninvalid');
        $this->assertResponseNotContains('oninput');
    }
}
