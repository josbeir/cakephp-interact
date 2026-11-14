<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Security;

use Cake\Core\Configure;
use Cake\Error\Renderer\WebExceptionRenderer;
use Interact\Test\TestSuite\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies CSRF, snapshot integrity, action allowlists, and current permission checks.
 * Real requests retain the demo application, middleware, and shared isolated HTTP harness.
 */
class RequestProtectionHttpTest extends HttpTestCase
{
    /**
     * Exercise both application defaults and explicitly bounded snapshot issuance.
     *
     * @return iterable<string, array{?int}>
     */
    public static function snapshotLifetimes(): iterable
    {
        yield 'unlimited' => [null];
        yield 'finite' => [60];
    }

    /**
     * Apply signing configuration to real page mounts and their CSRF-protected updates.
     */
    #[DataProvider('snapshotLifetimes')]
    public function testSnapshotLifetimeConfigurationReachesTheHttpRuntime(?int $ttl): void
    {
        /** @var mixed $previous */
        $previous = Configure::read('Interact.snapshots');
        Configure::write('Interact.snapshots', ['ttl' => $ttl]);
        try {
            $before = time();
            $mount = $this->mounted(url: '/playground');
            $decoded = base64_decode(strtr(explode('.', $mount['snapshot'])[0], '-_', '+/'), true);
            $this->assertIsString($decoded);
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('expires', $envelope);
            if ($ttl === null) {
                $this->assertNull($envelope['expires']);
            } else {
                $this->assertGreaterThanOrEqual($before + $ttl, $envelope['expires']);
                $this->assertLessThanOrEqual(time() + $ttl, $envelope['expires']);
            }

            $this->update(['snapshot' => $mount['snapshot'], 'action' => 'adjust', 'args' => [5]]);
            $this->assertResponseOk();
            $this->assertResponseContains('"count":5');
        } finally {
            if ($previous === null) {
                Configure::delete('Interact.snapshots');
            } else {
                Configure::write('Interact.snapshots', $previous);
            }
        }
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
        $this->assertHeader('Interact', 'true');
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
}
