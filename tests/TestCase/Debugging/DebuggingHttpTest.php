<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Debugging;

use Cake\Core\Configure;
use Cake\Error\Renderer\WebExceptionRenderer;
use Cake\Event\EventManager;
use Interact\Runtime\Values;
use Interact\Test\TestSuite\Http\HttpTestCase;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;

/**
 * Exercises native dumps through real CSRF-protected requests and CakePHP error handling.
 *
 * Diagnostics must remain separate from HTML and snapshots across ordinary, validation,
 * renderless, upload, and streamed interactions.
 */
class DebuggingHttpTest extends HttpTestCase
{
    /**
     * Send the browser's normal request marker as well as its CSRF-protected envelope.
     *
     * @param array<string, mixed> $data Update envelope fields.
     */
    protected function update(array $data): void
    {
        $this->requestAsJson();
        // Each simulated HTTP request boots a fresh container; discard its predecessor's listener.
        EventManager::instance()->off('Exception.beforeRender');
        $this->configRequest(['headers' => ['X-CSRF-Token' => $this->csrf, 'Interact' => 'true']]);
        $this->post('/_interact/update', json_encode(['version' => 1] + $data, JSON_THROW_ON_ERROR));
    }

    public function testDumpsPreserveActionsMaskingAndSignedState(): void
    {
        $mount = $this->mounted(url: '/demo/debugging');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'inspect', 'updates' => ['value' => 'Draft']]);
        $this->assertResponseOk();
        $payload = $this->payload();
        $output = Values::map($payload['diagnostics']);
        static::assertIsString($output['output']);
        static::assertStringContainsString('authorized', $output['output']);
        static::assertStringContainsString('initialized', $output['output']);
        static::assertStringContainsString('updated', $output['output']);
        static::assertStringContainsString('Draft', $output['output']);
        static::assertStringContainsString('********', $output['output']);
        static::assertStringNotContainsString(hash('sha256', 'Draft'), $output['output']);
        static::assertTrue($output['viewer']);
        static::assertFalse($output['truncated']);
        static::assertSame('Inspected', Values::map($payload['state'])['value']);
        static::assertIsString($payload['html']);
        static::assertStringNotContainsString('authorized', $payload['html']);
        static::assertStringNotContainsString('debugExecuted', $payload['html']);
        static::assertIsString($payload['snapshot']);
        $snapshot = base64_decode(strtr(explode('.', $payload['snapshot'])[0], '-_', '+/'), true);
        static::assertIsString($snapshot);
        static::assertStringNotContainsString('diagnostics', $snapshot);

        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'inspectOnly']);
        $this->assertResponseOk();
        static::assertNull($this->payload()['html']);
        static::assertArrayHasKey('diagnostics', $this->payload());
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'inspect', 'args' => ['validation']]);
        $this->assertResponseCode(422);
        static::assertArrayHasKey('diagnostics', $this->payload());
        static::assertSame(['Invalid value.'], Values::map($this->payload()['errors'])['value']);
    }

    public function testOutputSurvivesExpectedAndUnexpectedFailuresWithoutChangingCakeLogging(): void
    {
        $mount = $this->mounted(url: '/demo/debugging');
        Configure::write('Error.exceptionRenderer', WebExceptionRenderer::class);
        foreach (['rejected' => 400, 'failure' => 500] as $mode => $status) {
            $this->update(['snapshot' => $mount['snapshot'], 'action' => 'inspect', 'args' => [$mode]]);
            $this->assertResponseCode($status);
            $output = Values::map($this->payload()['diagnostics']);
            static::assertIsString($output['output']);
            static::assertStringContainsString('global dump', $output['output']);
            static::assertArrayHasKey('error', $this->payload());
            static::assertArrayNotHasKey('state', $this->payload());
        }
    }

    public function testViewerOptOutRetainsNetworkDiagnosticsAndDebugOptOutLeavesNativeOutputAlone(): void
    {
        $mount = $this->mounted(url: '/demo/debugging');
        Configure::write('Interact.debug.viewer', false);
        try {
            $this->update(['snapshot' => $mount['snapshot'], 'action' => 'inspect']);
            $this->assertResponseOk();
            static::assertFalse(Values::map($this->payload()['diagnostics'])['viewer']);
            Configure::write('Interact.debug.enabled', false);
            ob_start();
            try {
                $this->update(['snapshot' => $mount['snapshot'], 'action' => 'inspect']);
            } finally {
                $output = (string)ob_get_clean();
            }

            static::assertStringContainsString('global dump', $output);
            static::assertArrayNotHasKey('diagnostics', $this->payload());
        } finally {
            Configure::delete('Interact.debug');
        }
    }

    public function testUploadHooksAreCapturedWithTheirNormalMultipartResponse(): void
    {
        $mount = $this->mounted(url: '/demo/debugging');
        $stream = new Stream('php://temp', 'w+');
        $stream->write('uploaded bytes');
        $stream->rewind();
        $this->replaceRequest([
            'headers' => [
                'X-CSRF-Token' => $this->csrf,
                'Content-Type' => 'multipart/form-data',
                'Interact' => 'true',
            ],
            'files' => ['file' => new UploadedFile($stream, 14, UPLOAD_ERR_OK, 'debug.txt', 'text/plain')],
        ]);
        $this->post('/_interact/update', ['payload' => json_encode([
            'version' => 1,
            'snapshot' => $mount['snapshot'],
            'upload' => ['kind' => 'stage', 'field' => 'file'],
        ], JSON_THROW_ON_ERROR)]);
        $this->assertResponseOk();
        $output = Values::map($this->payload()['diagnostics']);
        static::assertIsString($output['output']);
        static::assertStringContainsString('updated', $output['output']);
        static::assertStringContainsString('debug.txt', (string)$this->response?->getBody());
    }

    public function testStreamingDiagnosticsPrecedeTheirProgressAndTerminalFrames(): void
    {
        $mount = $this->mounted(url: '/demo/debugging');
        foreach (['success', 'failure'] as $mode) {
            $this->replaceRequest(['headers' => [
                'X-CSRF-Token' => $this->csrf,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
                'Interact' => 'true',
            ]]);
            $body = '';
            ob_start(static function (string $output) use (&$body): string {
                $body .= $output;

                return '';
            });
            try {
                $this->post('/_interact/update', json_encode([
                    'version' => 1,
                    'snapshot' => $mount['snapshot'],
                    'action' => 'inspectStream',
                    'args' => [$mode],
                ], JSON_THROW_ON_ERROR));
                $body .= (string)$this->response?->getBody();
            } finally {
                ob_end_flush();
            }

            $this->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
            static::assertStringContainsString('"type":"diagnostic"', $body);
            static::assertStringContainsString('before progress', $body);
            static::assertStringContainsString('"type":"target"', $body);
            static::assertStringContainsString($mode === 'success' ? '"type":"result"' : '"type":"failure"', $body);
        }
    }

    public function testPendingInitializationCapturesHooksAndKeepsTemplateDumpsInsideHtml(): void
    {
        $mount = $this->mounted(url: '/demo/debugging?template=1&loading=manual');
        $this->update(['snapshot' => $mount['snapshot'], 'initialize' => true]);
        $this->assertResponseOk();
        $payload = $this->payload();
        static::assertIsString($payload['html']);
        static::assertStringContainsString('template output', $payload['html']);
        $output = Values::map($payload['diagnostics']);
        static::assertIsString($output['output']);
        static::assertStringContainsString('initialized', $output['output']);
        static::assertStringNotContainsString('template output', $output['output']);
    }

    public function testPublicDemoInspectsEditableDraftsAndValidatesWithoutPersistence(): void
    {
        $mount = $this->mounted(url: '/debugging');
        $this->assertResponseContains('Stream three dumps');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'action' => 'inspect',
            'updates' => ['message' => 'User draft'],
        ]);
        $this->assertResponseOk();
        $payload = $this->payload();
        static::assertSame(1, Values::map($payload['state'])['inspections']);
        $output = Values::map($payload['diagnostics']);
        static::assertIsString($output['output']);
        static::assertStringContainsString('User draft', $output['output']);
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'inspectOnly']);
        $this->assertResponseOk();
        static::assertNull($this->payload()['html']);
        static::assertSame(2, Values::map($this->payload()['state'])['inspections']);
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'action' => 'inspectValidation',
            'updates' => ['message' => ''],
        ]);
        $this->assertResponseCode(422);
        static::assertSame(['Enter a message before validating.'], Values::map($this->payload()['errors'])['message']);
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'action' => 'inspectValidation',
            'updates' => ['message' => 'Corrected'],
        ]);
        $this->assertResponseOk();
        static::assertSame('Message is valid', Values::map($this->payload()['state'])['status']);
    }

    /**
     * Decode only real JSON envelopes, exposing malformed response output immediately.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return Values::map(json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }
}
