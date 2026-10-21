<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\Test\TestSuite\HttpTestCase;

/**
 * Exercises real pending mounts through native rendering, discovery, and CSRF middleware.
 * Authorization is reevaluated after the initial page rather than frozen in the ticket.
 */
class LoadingHttpTest extends HttpTestCase
{
    public function testInitializationRendersTheReportAndThenAcceptsNormalActions(): void
    {
        $pending = $this->mounted(2, '/loading');
        $this->assertResponseNotContains('matching resources');
        $this->update(['snapshot' => $pending['snapshot'], 'initialize' => true, 'pageQuery' => '?manual_q=security']);
        $this->assertResponseOk();
        $this->assertResponseContains('"search":"security"');
        $this->assertResponseContains('"mounts":1');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'refreshReport']);
        $this->assertResponseOk();
        $this->assertResponseContains('"refreshes":1');
    }

    public function testInitializationRequiresTheApplicationCsrfToken(): void
    {
        $pending = $this->mounted(2, '/loading');
        $this->requestAsJson();
        $this->post('/_interact/update', json_encode([
            'version' => 1,
            'snapshot' => $pending['snapshot'],
            'initialize' => true,
        ], JSON_THROW_ON_ERROR));
        $this->assertResponseCode(403);
        $this->assertResponseContains('csrf_failed');
    }

    public function testInitializationRechecksPermissionAndRejectsTicketTampering(): void
    {
        $pending = $this->mounted(2, '/loading');
        $this->session(['demo' => ['role' => 'viewer']]);
        $this->update(['snapshot' => $pending['snapshot'], 'initialize' => true]);
        $this->assertResponseCode(403);
        $this->update(['snapshot' => $pending['snapshot'] . 'tampered', 'initialize' => true]);
        $this->assertResponseCode(409);
        $this->assertResponseContains('invalid_snapshot');
    }
}
