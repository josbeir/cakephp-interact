<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\ServerRequest;
use Interact\Test\TestSuite\HttpTestCase;

/**
 * Verifies request identification without granting protocol or authorization capabilities.
 *
 * Only generated island responses carry the marker; ordinary documents retain
 * their native HTTP behavior even when a caller supplies the request header.
 */
class HttpMarkerTest extends HttpTestCase
{
    public function testBootstrapRegistersAnExactHeaderDetector(): void
    {
        $this->mounted();
        $request = new ServerRequest();
        static::assertFalse($request->is('interact'));
        foreach (['true', 'false', '1', '', 'TRUE', 'true, true'] as $value) {
            $marked = $request->withHeader('interact', $value);
            $marked->clearDetectorCache();
            static::assertSame($value === 'true', $marked->is('interact'));
        }

        $json = $request->withHeader('Accept', 'application/json');
        static::assertFalse($json->is('interact'));
    }

    public function testDocumentsDoNotEchoTheMarkerAndUnmarkedUpdatesRemainSupported(): void
    {
        $this->configRequest(['headers' => ['Interact' => 'true']]);
        $mount = $this->mounted();
        static::assertFalse($this->response?->hasHeader('Interact'));
        $this->update(['snapshot' => $mount['snapshot']]);
        $this->assertResponseOk();
        $this->assertHeader('Interact', 'true');
    }
}
