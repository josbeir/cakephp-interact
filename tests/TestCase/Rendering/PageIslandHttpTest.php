<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Rendering;

use Interact\Test\TestSuite\Http\HttpTestCase;

/**
 * Verifies whole-page islands reuse the controller action template on updates.
 * Real requests retain the demo application, middleware, and shared isolated HTTP harness.
 */
class PageIslandHttpTest extends HttpTestCase
{
    public function testWholePageReusesActionTemplate(): void
    {
        $mount = $this->mounted(url: '/page');
        $this->assertResponseContains('Page island');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'next']);
        $this->assertResponseOk();
        $this->assertResponseContains('Page 2');
        $this->assertResponseContains('Escaping &lt;script&gt;alert(1)');
    }
}
