<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Debugging;

use Cake\Core\Configure;
use Cake\Error\Debug\HtmlFormatter;
use Cake\Error\Debugger;
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

    public function testDumpsPreserveNativeFormattingAndSignedState(): void
    {
        $mount = $this->mounted(url: '/demo/debugging');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'inspect', 'updates' => ['value' => 'Draft']]);
        $this->assertResponseOk();
        $payload = $this->payload();
        $output = Values::map($payload['diagnostics']);
        $this->assertIsString($output['output']);
        $this->assertStringContainsString('authorized', $output['output']);
        $this->assertStringContainsString('initialized', $output['output']);
        $this->assertStringContainsString('updated', $output['output']);
        $this->assertStringContainsString('Draft', $output['output']);
        $this->assertStringContainsString(
            new HtmlFormatter()->dump(Debugger::exportVarAsNodes([
                'value' => 'Draft',
                'password' => hash('sha256', 'Draft'),
            ])),
            $output['output'],
        );
        $this->assertTrue($output['viewer']);
        $this->assertFalse($output['truncated']);
        $this->assertSame('Inspected', Values::map($payload['state'])['value']);
        $this->assertIsString($payload['html']);
        $this->assertStringNotContainsString('authorized', $payload['html']);
        $this->assertStringNotContainsString('debugExecuted', $payload['html']);
        $this->assertIsString($payload['snapshot']);
        $snapshot = base64_decode(strtr(explode('.', $payload['snapshot'])[0], '-_', '+/'), true);
        $this->assertIsString($snapshot);
        $this->assertStringNotContainsString('diagnostics', $snapshot);

        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'inspectOnly']);
        $this->assertResponseOk();
        $this->assertNull($this->payload()['html']);
        $this->assertArrayHasKey('diagnostics', $this->payload());
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'inspect', 'args' => ['validation']]);
        $this->assertResponseCode(422);
        $this->assertArrayHasKey('diagnostics', $this->payload());
        $this->assertSame(['Invalid value.'], Values::map($this->payload()['errors'])['value']);
    }

    public function testOutputSurvivesExpectedAndUnexpectedFailuresWithoutChangingCakeLogging(): void
    {
        $mount = $this->mounted(url: '/demo/debugging');
        Configure::write('Error.exceptionRenderer', WebExceptionRenderer::class);
        foreach (['rejected' => 400, 'failure' => 500] as $mode => $status) {
            $this->update(['snapshot' => $mount['snapshot'], 'action' => 'inspect', 'args' => [$mode]]);
            $this->assertResponseCode($status);
            $output = Values::map($this->payload()['diagnostics']);
            $this->assertIsString($output['output']);
            $this->assertStringContainsString('global dump', $output['output']);
            $this->assertArrayHasKey('error', $this->payload());
            $this->assertArrayNotHasKey('state', $this->payload());
        }
    }

    public function testViewerOptOutRetainsNetworkDiagnosticsAndDebugOptOutLeavesNativeOutputAlone(): void
    {
        $mount = $this->mounted(url: '/demo/debugging');
        Configure::write('Interact.debug.viewer', false);
        try {
            $this->update(['snapshot' => $mount['snapshot'], 'action' => 'inspect']);
            $this->assertResponseOk();
            $this->assertFalse(Values::map($this->payload()['diagnostics'])['viewer']);
            Configure::write('Interact.debug.enabled', false);
            ob_start();
            try {
                $this->update(['snapshot' => $mount['snapshot'], 'action' => 'inspect']);
            } finally {
                $output = (string)ob_get_clean();
            }

            $this->assertStringContainsString('global dump', $output);
            $this->assertArrayNotHasKey('diagnostics', $this->payload());
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
        $this->assertIsString($output['output']);
        $this->assertStringContainsString('updated', $output['output']);
        $this->assertStringContainsString('debug.txt', (string)$this->response?->getBody());
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
            $this->assertStringContainsString('"type":"diagnostic"', $body);
            $this->assertStringContainsString('before progress', $body);
            $this->assertStringContainsString('"type":"target"', $body);
            $this->assertStringContainsString($mode === 'success' ? '"type":"result"' : '"type":"failure"', $body);
        }
    }

    public function testPendingInitializationCapturesHooksAndKeepsTemplateDumpsInsideHtml(): void
    {
        $mount = $this->mounted(url: '/demo/debugging?template=1&loading=manual');
        $this->update(['snapshot' => $mount['snapshot'], 'initialize' => true]);
        $this->assertResponseOk();
        $payload = $this->payload();
        $this->assertIsString($payload['html']);
        $this->assertStringContainsString('template output', $payload['html']);
        $output = Values::map($payload['diagnostics']);
        $this->assertIsString($output['output']);
        $this->assertStringContainsString('initialized', $output['output']);
        $this->assertStringNotContainsString('template output', $output['output']);
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
        $this->assertSame(1, Values::map($payload['state'])['inspections']);
        $output = Values::map($payload['diagnostics']);
        $this->assertIsString($output['output']);
        $this->assertStringContainsString('User draft', $output['output']);
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'inspectOnly']);
        $this->assertResponseOk();
        $this->assertNull($this->payload()['html']);
        $this->assertSame(2, Values::map($this->payload()['state'])['inspections']);
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'action' => 'inspectValidation',
            'updates' => ['message' => ''],
        ]);
        $this->assertResponseCode(422);
        $this->assertSame(['Enter a message before validating.'], Values::map($this->payload()['errors'])['message']);
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'action' => 'inspectValidation',
            'updates' => ['message' => 'Corrected'],
        ]);
        $this->assertResponseOk();
        $this->assertSame('Message is valid', Values::map($this->payload()['state'])['status']);
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
