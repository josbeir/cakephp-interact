<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Http;

use Cake\Core\Configure;
use Cake\Error\Renderer\WebExceptionRenderer;
use Interact\Test\TestSuite\Http\HttpTestCase;

/**
 * Verifies middleware and unexpected exceptions use the same safe HTTP failure contract.
 * Real requests retain the demo application, middleware, and shared isolated HTTP harness.
 */
class FailureHttpTest extends HttpTestCase
{
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
