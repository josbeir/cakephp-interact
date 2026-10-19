<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\AttributeResolver\AttributeResolver;
use Cake\Core\Configure;
use Cake\Http\Exception\InvalidCsrfTokenException;
use Cake\Http\Response;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use TestApp\Application;

/**
 * Exercises real CakePHP controllers, DI, middleware, discovery, templates, and protected AJAX
 * updates.
 */
class HttpTest extends TestCase
{
    use IntegrationTestTrait;

    protected string $csrf = '';

    protected function setUp(): void
    {
        parent::setUp();
        require_once APP . 'config/bootstrap.php';
        Configure::write('App.namespace', 'TestApp');
        AttributeResolver::drop('interact');
        AttributeResolver::setConfig('interact', [
            'paths' => ['src/Island/*.php'],
            'basePath' => APP,
            'cache' => false,
        ]);
        Router::reload();
        $this->configApplication(Application::class, [CONFIG]);
        $this->disableErrorHandlerMiddleware();
    }

    /**
     * Mount the real demo and retain its CSRF cookie and selected island identity.
     *
     * @return array{snapshot: string, id: string} Mount data extracted from the initial real HTML.
     */
    protected function mounted(int $index = 0, string $url = '/'): array
    {
        $this->get($url);
        $this->assertResponseOk();
        $document = HTMLDocument::createFromString((string)$this->response?->getBody());
        $node = new XPath($document)->query('//*[@data-interact-id]')
            ->item($index);
        $this->assertInstanceOf(Element::class, $node);

        $this->csrf = (string)$node->getAttribute('data-interact-csrf');
        $this->assertInstanceOf(Response::class, $this->response);
        $cookies = $this->response->getCookieCollection();
        $cookie = $cookies->get('csrfToken')->getValue();
        $this->assertIsString($cookie);
        $this->cookie('csrfToken', $cookie);

        return [
            'snapshot' => (string)$node->getAttribute('data-interact-snapshot'),
            'id' => (string)$node->getAttribute('data-interact-id'),
        ];
    }

    /**
     * Submit typed JSON through the real update route with the current CSRF token.
     *
     * @param array<string, mixed> $data Client JSON, retaining numeric and boolean types.
     */
    protected function update(array $data): void
    {
        $this->requestAsJson();
        $this->configRequest(['headers' => ['X-CSRF-Token' => $this->csrf]]);
        $this->post('/_interact/update', json_encode(['version' => 1] + $data, JSON_THROW_ON_ERROR));
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
        static::assertStringContainsString('data-interact-preserve', $body);
        static::assertStringContainsString('CakePHP', $body);
        static::assertStringNotContainsString('<!doctype', $body);
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
        $this->assertResponseContains('A title is required.');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'updates' => ['title' => 'Alpine patterns'],
            'action' => 'save',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('article-saved');
    }

    public function testUnknownActionsAndTamperingFailClosed(): void
    {
        $mount = $this->mounted();
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'mount']);
        $this->assertResponseCode(400);
        $this->update(['snapshot' => $mount['snapshot'] . 'x']);
        $this->assertResponseCode(409);
    }

    public function testPermissionIsCheckedAgainAfterMounting(): void
    {
        $mount = $this->mounted(1);
        $this->session(['demo' => ['role' => 'viewer']]);
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'save']);
        $this->assertResponseCode(403);
    }

    public function testDependentChoicesAndUnrelatedDrafts(): void
    {
        $mount = $this->mounted(4);
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['country' => 'NL', 'notes' => 'keep draft']]);
        $this->assertResponseOk();
        $this->assertResponseContains('Amsterdam');
        $this->assertResponseContains('keep draft');
        $this->assertResponseNotContains('Brussels');
    }

    public function testWholePageReusesActionTemplate(): void
    {
        $mount = $this->mounted(url: '/page');
        $this->assertResponseContains('Whole-page listing');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'next']);
        $this->assertResponseOk();
        $this->assertResponseContains('Page 2');
        $this->assertResponseContains('Escaping &lt;script&gt;alert(1)');
    }

    public function testUpdatesStillRequireCsrfProtection(): void
    {
        $mount = $this->mounted();
        $this->requestAsJson();
        $this->expectException(InvalidCsrfTokenException::class);
        $this->post('/_interact/update', json_encode([
            'version' => 1,
            'snapshot' => $mount['snapshot'],
        ], JSON_THROW_ON_ERROR));
    }

    public function testAttributeRoleRouteChangesTheCurrentSession(): void
    {
        $this->mounted(1);
        $this->configRequest(['headers' => ['X-CSRF-Token' => $this->csrf]]);
        $this->post('/demo/role/viewer');
        $this->assertResponseOk();
        $this->assertSession('viewer', 'demo.role');
    }
}
