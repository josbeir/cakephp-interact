<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\AttributeResolver\AttributeResolver;
use Cake\Core\Configure;
use Cake\Error\Renderer\WebExceptionRenderer;
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

    /**
     * Boot isolated demo routes and expose unexpected exceptions to integration assertions.
     */
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

    public function testResourceLibraryRendersOnlyOnePageAndKeepsRowsOutsideState(): void
    {
        $this->mounted(url: '/search');
        $this->assertResponseContains('1,200 sample resources');
        $this->assertResponseContains('Showing 1–20 of');
        $this->assertResponseContains('aria-current="page">Search library');
        $document = HTMLDocument::createFromString((string)$this->response?->getBody());
        $xpath = new XPath($document);
        $xpath->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
        static::assertSame(20, $xpath->query('//html:table/html:tbody/html:tr')->length);
        $root = $xpath->query('//*[@data-interact-state]')->item(0);
        static::assertInstanceOf(Element::class, $root);
        static::assertJsonStringEqualsJsonString(
            '{"search":"","category":"","level":"","sort":"title","pageSize":20,"page":1}',
            (string)$root->getAttribute('data-interact-state'),
        );
        $options = $xpath->query('//html:select[@*[name()="x-interact:model.change" and .="sort"]]/html:option');
        $labels = [];
        foreach ($options as $option) {
            $labels[] = $option->textContent;
        }

        static::assertSame(['Title: A–Z', 'Title: Z–A', 'Shortest read first', 'Longest read first'], $labels);
        $selected = $xpath->query(
            '//html:select[@*[name()="x-interact:model.change" and .="sort"]]/html:option[@selected]',
        );
        static::assertSame(1, $selected->length);
        $selectedOption = $selected->item(0);
        static::assertInstanceOf(Element::class, $selectedOption);
        static::assertSame('title', $selectedOption->getAttribute('value'));
    }

    public function testResourceSearchCombinesFiltersAndResetsPaginationAfterEdits(): void
    {
        $mount = $this->mounted(url: '/search');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'goTo', 'args' => [999]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":60');
        $this->assertResponseContains('Showing 1,181\u20131,200');
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'updates' => [
                'search' => 'validation',
                'category' => 'cakephp',
                'level' => 'beginner',
                'sort' => 'shortest',
            ],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":1');
        $this->assertResponseContains('Showing 1\u20135 of');
        $this->assertResponseContains('Entity validation');
        $this->assertResponseNotContains('Input validation');
        $this->assertResponseNotContains('Undefined variable');
        $this->assertResponseContains('value=\"shortest\" selected');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'goTo', 'args' => [-10]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":1');
    }

    public function testResourceSearchChangesPageSizeAndRestoresTheFullLibrary(): void
    {
        $mount = $this->mounted(url: '/search');
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['pageSize' => 50]]);
        $this->assertResponseOk();
        $this->assertResponseContains('Showing 1\u201350 of');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'goTo', 'args' => [999]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"page":24');
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['search' => '%']]);
        $this->assertResponseOk();
        $this->assertResponseContains('No resources found');
        $this->assertResponseContains('"page":1');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'clear']);
        $this->assertResponseOk();
        $this->assertResponseContains('Showing 1\u201320 of');
        $this->assertResponseContains('"search":""');
        $this->assertResponseContains('"pageSize":20');
        $this->assertResponseNotContains('No resources found');
    }

    public function testResourceSearchRejectsUnsupportedFiltersAndPageAssignments(): void
    {
        $mount = $this->mounted(url: '/search');
        foreach ([
            ['category' => 'unknown'],
            ['level' => 'unknown'],
            ['sort' => 'Resources.id'],
            ['pageSize' => 0],
            ['page' => 99],
            ['search' => str_repeat('x', 201)],
        ] as $updates) {
            $this->update(['snapshot' => $mount['snapshot'], 'updates' => $updates]);
            $this->assertResponseCode(400);
        }
    }

    public function testPlaygroundMountsDistinctExamplesWithinTheSharedLayout(): void
    {
        $this->mounted(url: '/playground');
        $this->assertResponseContains('Meet your next interaction.');
        $this->assertResponseContains('href="/demo.css"');
        $this->assertResponseContains('aria-current="page">Playground');
        $this->assertResponseContains('First counter');
        $this->assertResponseContains('Second counter');
        $this->assertResponseContains('Plan a workshop');
        $this->assertResponseContains('A little list of things');
    }

    public function testCountersUseTypedActionsAndRetainTheirIndependentMountProps(): void
    {
        $first = $this->mounted(url: '/playground');
        $second = $this->mounted(1, '/playground');
        static::assertNotSame($first['id'], $second['id']);
        $this->update(['snapshot' => $first['snapshot'], 'action' => 'adjust', 'args' => [5]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"count":5');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'reset']);
        $this->assertResponseContains('"count":0');
        $this->update(['snapshot' => $second['snapshot'], 'action' => 'adjust', 'args' => [-1]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"count":4');
    }

    public function testCounterRejectsUnsupportedIncrementsAndDirectStateAssignments(): void
    {
        $mount = $this->mounted(url: '/playground');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'adjust', 'args' => [99]]);
        $this->assertResponseCode(400);
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['count' => 99]]);
        $this->assertResponseCode(400);
    }

    public function testWorkshopQuoteCalculatesExtrasAndValidatesConditionalFields(): void
    {
        $mount = $this->mounted(2, '/playground');
        $this->assertResponseContains('€90.00');
        $this->assertResponseNotContains('placeholder="Your company"');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'updates' => [
                'format' => 'studio',
                'attendees' => 3,
                'extras' => ['materials', 'recording'],
                'business' => true,
            ],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('\u20ac300.00');
        $this->assertResponseContains('Company name');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'confirm']);
        $this->assertResponseCode(422);
        $this->assertResponseContains('Enter a company name');
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'updates' => ['company' => 'Cake Studio'],
            'action' => 'confirm',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('Quote confirmed.');
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['business' => false]]);
        $this->assertResponseOk();
        $this->assertResponseNotContains('Quote confirmed.');
        $this->assertResponseNotContains('placeholder=');
        $this->assertResponseContains('"company":"Cake Studio"');
    }

    public function testWorkshopRejectsInvalidChoicesAndClientSuppliedTotals(): void
    {
        $mount = $this->mounted(2, '/playground');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'updates' => ['format' => 'unknown', 'attendees' => 0, 'extras' => [['unexpected' => true]]],
            'action' => 'confirm',
        ]);
        $this->assertResponseCode(422);
        $this->assertResponseContains('Choose an online or studio workshop.');
        $this->assertResponseContains('Choose between 1 and 12 attendees.');
        $this->assertResponseContains('Choose an available workshop extra.');
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['total' => 1]]);
        $this->assertResponseCode(400);
    }

    public function testTaskBoardAddsTogglesAndRemovesRowsByStableIdentity(): void
    {
        $mount = $this->mounted(3, '/playground');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'updates' => ['draft' => 'Try typed actions'],
            'action' => 'add',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('"id":4,"text":"Try typed actions","done":false');
        $this->assertResponseContains('"draft":""');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'toggle', 'args' => [4]]);
        $this->assertResponseOk();
        $this->assertResponseContains('"id":4,"text":"Try typed actions","done":true');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'remove', 'args' => [4]]);
        $this->assertResponseOk();
        $this->assertResponseNotContains('Try typed actions');
        $this->assertResponseContains('"id":3,"text":"Build something with CakePHP"');
    }

    public function testTaskBoardValidatesDraftsEscapesTextAndRejectsUnknownRows(): void
    {
        $mount = $this->mounted(3, '/playground');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'add']);
        $this->assertResponseCode(422);
        $this->assertResponseContains('Use 1 to 80 characters');
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'updates' => ['draft' => '<img src=x onerror=alert(1)>'],
            'action' => 'add',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('&lt;img src=x onerror=alert(1)&gt;');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'toggle', 'args' => [999]]);
        $this->assertResponseCode(400);
    }

    public function testDependentChoicesConfirmAValidCityWithoutLosingNotes(): void
    {
        $mount = $this->mounted(4);
        $this->update([
            'snapshot' => $mount['snapshot'],
            'updates' => ['cityId' => 1, 'notes' => 'Keep my draft'],
            'action' => 'confirm',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('City confirmed.');
        $this->assertResponseContains('Keep my draft');
    }

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

    public function testDemoIgnoresCsrfCookiesFromOtherLocalApplications(): void
    {
        $foreignCookie = base64_encode(str_repeat('x', 56));
        $this->cookie('csrfToken', $foreignCookie);
        $mount = $this->mounted(1);
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'save']);
        $this->assertResponseOk();
        $this->assertResponseContains('article-saved');
    }

    public function testCsrfFailureReturnsAnHttpErrorInsteadOfAFatalDocument(): void
    {
        Configure::write('Error.exceptionRenderer', WebExceptionRenderer::class);
        Configure::write('Error.log', false);
        $this->requestAsJson();
        $this->post('/_interact/update', '{"version":1}');
        $this->assertResponseCode(403);
        $this->assertContentType('application/json');
        $this->assertResponseNotContains('Fatal error');
        $this->assertResponseContains('csrf_failed');
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
        /** @var mixed $payload */
        $payload = json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR);
        static::assertIsArray($payload);
        static::assertSame(['title' => ['A title is required.']], $payload['errors']);
        static::assertSame([], $payload['events']);
        static::assertIsString($payload['html']);
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $payload['html'] . '</body></html>');
        $input = new XPath($document)->query('//*[@name="title"]')
            ->item(0);
        static::assertInstanceOf(Element::class, $input);
        static::assertSame('', $input->getAttribute('value'));
        static::assertSame('true', $input->getAttribute('aria-invalid'));
        $error = $document->getElementById((string)$input->getAttribute('aria-describedby'));
        static::assertInstanceOf(Element::class, $error);
        static::assertSame('alert', $error->getAttribute('role'));
        static::assertSame('A title is required.', $error->textContent);
        $title = '<script>' . str_repeat('x', 101) . '</script>';
        $this->update(['snapshot' => $this->nextSnapshot(), 'updates' => ['title' => $title], 'action' => 'save']);
        $this->assertResponseCode(422);
        /** @var mixed $payload */
        $payload = json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR);
        static::assertIsArray($payload);
        static::assertIsArray($payload['state']);
        static::assertSame(['articleId' => 1, 'title' => $title, 'saved' => false], $payload['state']);
        static::assertIsString($payload['html']);
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $payload['html'] . '</body></html>');
        $input = new XPath($document)->query('//*[@name="title"]')
            ->item(0);
        static::assertInstanceOf(Element::class, $input);
        static::assertSame($title, $input->getAttribute('value'));
        static::assertStringNotContainsString('<script>', $payload['html']);
        static::assertStringContainsString('&lt;script&gt;', $payload['html']);
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
        $this->post('/_interact/update', json_encode([
            'version' => 1,
            'snapshot' => $mount['snapshot'],
        ], JSON_THROW_ON_ERROR));
        $this->assertResponseCode(403);
        $this->assertResponseContains('csrf_failed');
    }

    public function testAttributeRoleRouteChangesTheCurrentSession(): void
    {
        $this->mounted(1);
        $this->configRequest(['headers' => ['X-CSRF-Token' => $this->csrf]]);
        $this->post('/demo/role/viewer');
        $this->assertResponseOk();
        $this->assertSession('viewer', 'demo.role');
    }

    public function testMiddlewareAndUnexpectedExceptionsUseTheSameSafeContract(): void
    {
        Configure::write('Error.exceptionRenderer', WebExceptionRenderer::class);
        Configure::write('Error.log', false);
        $mount = $this->mounted(4, '/playground');
        foreach ([
            ['server',          500, 'server_error'],
            ['unavailable',     503, 'server_error'],
            ['unauthenticated', 401, 'unauthenticated'],
            ['expired',         409, 'snapshot_expired'],
            ['session',         409, 'session_changed'],
        ] as [$failure, $status, $code]) {
            $this->update(['snapshot' => $mount['snapshot'], 'action' => 'simulate', 'args' => [$failure]]);
            $this->assertResponseCode($status);
            $this->assertResponseContains($code);
            $this->assertResponseNotContains('Private fixture diagnostics');
            $this->assertHeader('Cache-Control', 'no-store');
        }
    }
}
