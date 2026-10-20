<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\I18n\DateTime;
use Interact\Test\TestSuite\HttpTestCase;

/**
 * Exercises polling examples through real signed updates and CakePHP middleware.
 *
 * The browser adds scheduling only: computed data, actions, permissions, and CSRF
 * retain their existing application and transport boundaries.
 */
class PollingHttpTest extends HttpTestCase
{
    public function testRefreshRecomputesClockDataWithoutTransportingIt(): void
    {
        DateTime::setTestNow(new DateTime('2026-10-20 12:00:00'));
        try {
            $mount = $this->mounted(5, '/playground');
            $this->assertResponseContains('12:00:00');
            DateTime::setTestNow(new DateTime('2026-10-20 12:00:05'));
            $this->update(['snapshot' => $mount['snapshot']]);
            $this->assertResponseOk();
            $this->assertResponseContains('12:00:05');
            $this->assertResponseContains('"state":{}');
            $this->assertResponseNotContains('<!doctype');
        } finally {
            DateTime::setTestNow();
        }
    }

    public function testDeclaredJobActionCompletesAndCanBeExplicitlyRestarted(): void
    {
        $this->session(['demo' => ['role' => 'viewer']]);
        $mount = $this->mounted(6, '/playground');
        $snapshot = $mount['snapshot'];
        foreach ([25, 50, 75, 100] as $progress) {
            $this->update(['snapshot' => $snapshot, 'action' => 'refreshStatus']);
            $this->assertResponseOk();
            $this->assertResponseContains('"progress":' . (string)$progress);
            $snapshot = $this->nextSnapshot();
        }

        $this->assertResponseContains('"running":false');
        $this->assertResponseContains('Simulated job complete.');
        $this->update(['snapshot' => $snapshot, 'action' => 'restart']);
        $this->assertResponseOk();
        $this->assertResponseContains('"progress":0,"running":true');
    }

    public function testPollingRejectsEditsToServerOwnedJobState(): void
    {
        $mount = $this->mounted(6, '/playground');
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['progress' => 100]]);
        $this->assertResponseCode(400);
    }

    public function testProtectedPollingRefreshRechecksPermission(): void
    {
        $mount = $this->mounted(0, '/demo/polling');
        $this->session(['demo' => ['role' => 'viewer']]);
        $this->update(['snapshot' => $mount['snapshot']]);
        $this->assertResponseCode(403);
    }

    public function testPollingStillRequiresTheApplicationsCsrfCookieAndToken(): void
    {
        $mount = $this->mounted(6, '/playground');
        $this->requestAsJson();
        $this->post('/_interact/update', json_encode([
            'version' => 1,
            'snapshot' => $mount['snapshot'],
            'action' => 'refreshStatus',
        ], JSON_THROW_ON_ERROR));
        $this->assertResponseCode(403);
        $this->assertResponseContains('csrf_failed');
    }
}
