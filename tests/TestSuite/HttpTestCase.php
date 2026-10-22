<?php
declare(strict_types=1);

namespace Interact\Test\TestSuite;

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use TestApp\Application;

/**
 * Shares real demo bootstrapping and signed, CSRF-protected island requests between HTTP suites.
 *
 * Shared helpers retain the CSRF cookie and current signed snapshot. Every update
 * still passes through CakePHP middleware and native rendering.
 */
abstract class HttpTestCase extends TestCase
{
    use IntegrationTestTrait;

    protected string $csrf = '';

    /**
     * Boot isolated demo routes and expose unexpected exceptions to integration assertions.
     */
    protected function setUp(): void
    {
        parent::setUp();
        require_once APP . 'config/bootstrap.php';
        Configure::write('App.namespace', 'TestApp');
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
        /** @var mixed $cookie */
        $cookie = $cookies->has(Application::CSRF_COOKIE)
            ? $cookies->get(Application::CSRF_COOKIE)->getValue()
            : $this->cookie[Application::CSRF_COOKIE] ?? null;
        $this->assertIsString($cookie);
        $this->cookie(Application::CSRF_COOKIE, $cookie);

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

    /**
     * Continue a demo interaction from the signed state returned by its last response.
     */
    protected function nextSnapshot(): string
    {
        /** @var mixed $payload */
        $payload = json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        /** @var mixed $snapshot */
        $snapshot = $payload['snapshot'] ?? null;
        $this->assertIsString($snapshot);

        return $snapshot;
    }
}
