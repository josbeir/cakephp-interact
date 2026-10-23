<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\Test\TestSuite\HttpTestCase;

/**
 * Verifies SSE negotiation cannot bypass the application's CSRF or snapshot middleware boundaries.
 */
class StreamingHttpTest extends HttpTestCase
{
    public function testInvalidCsrfAndTamperedSnapshotsFailBeforeStreaming(): void
    {
        $mount = $this->mounted(url: '/streaming');
        $this->requestAsJson();
        $this->configRequest(['headers' => [
            'Accept' => 'text/event-stream',
            'Interact' => 'true',
            'X-CSRF-Token' => bin2hex(random_bytes(16)),
        ]]);
        $this->post('/_interact/update', json_encode([
            'version' => 1,
            'snapshot' => $mount['snapshot'],
            'action' => 'generate',
        ], JSON_THROW_ON_ERROR));
        $this->assertResponseCode(403);
        $this->assertHeader('Interact', 'true');
        $this->assertHeader('Content-Type', 'application/json');
        $this->assertResponseContains('csrf_failed');
        $this->requestAsJson();
        $this->configRequest(['headers' => [
            'Accept' => 'text/event-stream',
            'Interact' => 'true',
            'X-CSRF-Token' => $this->csrf,
        ]]);
        $this->post('/_interact/update', json_encode([
            'version' => 1,
            'snapshot' => 'tampered',
            'action' => 'generate',
        ], JSON_THROW_ON_ERROR));
        $this->assertResponseCode(409);
        $this->assertHeader('Interact', 'true');
        $this->assertHeader('Content-Type', 'application/json');
        $this->assertResponseContains('invalid_snapshot');
    }
}
