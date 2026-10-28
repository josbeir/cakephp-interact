<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Debugging;

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Closure;
use Interact\Debugging\OutputCapture;
use Interact\Runtime\PreparedUpdate;
use Interact\Runtime\Result;
use Interact\Runtime\Values;
use Interact\Streaming\RequestContext;
use Interact\Streaming\SseResponse;
use PHPUnit\Framework\TestCase;

/**
 * Observes actual flushed SSE bytes while application output is being captured.
 *
 * Large Unicode dumps must preserve complete protocol frames and cannot delay
 * progress until action completion or bypass the per-request retention bound.
 */
class DebuggingStreamTest extends TestCase
{
    public function testLargeUnicodeDumpsAreChunkedAndProgressFlushesBeforeActionCompletion(): void
    {
        $capture = new OutputCapture(true);
        $capture->run(static function (): void {
            echo 'preparation';
        });
        /** @var list<string> $frames */
        $frames = [];
        $observed = 0;
        $update = new PreparedUpdate(true, 'debug', static function (Closure $send) use (&$observed, &$frames): Result {
            echo str_repeat('€<>', 70_000);
            $send(['type' => 'event', 'name' => 'progress', 'detail' => []]);
            $observed = count($frames);

            return new Result('debug', '', 'signed');
        });
        $response = $this->response($update, $capture);
        ob_start(static function (string $output) use (&$frames): string {
            if ($output !== '') {
                $frames[] = $output;
            }

            return '';
        });
        try {
            $this->assertSame('', (string)$response->getBody());
        } finally {
            ob_end_clean();
        }

        $this->assertGreaterThan(2, $observed);
        $this->assertSame(count($frames) - 1, $observed);
        $diagnostics = [];
        foreach ($frames as $frame) {
            $this->assertSame(1, substr_count($frame, "\n\n"));
            $this->assertLessThan(1_048_576, strlen($frame));
            $message = Values::map(json_decode(
                substr($frame, strlen("event: interact\ndata: "), -2),
                true,
                flags: JSON_THROW_ON_ERROR,
            ));
            if ($message['type'] === 'diagnostic') {
                $diagnostics[] = Values::map($message['diagnostics']);
            }
        }

        $this->assertSame('preparation', $diagnostics[0]['output']);
        $this->assertTrue($diagnostics[array_key_last($diagnostics)]['truncated']);
        foreach ($diagnostics as $diagnostic) {
            $this->assertIsString($diagnostic['output']);
            $this->assertTrue(mb_check_encoding($diagnostic['output'], 'UTF-8'));
        }
    }

    public function testProductionModeDiscardsPendingStreamingDiagnostics(): void
    {
        $capture = new OutputCapture(true);
        $capture->run(static function (): void {
            echo 'development only';
        });
        /** @var mixed $previous */
        $previous = Configure::read('debug');
        Configure::write('debug', false);
        $output = '';
        ob_start(static function (string $bytes) use (&$output): string {
            $output .= $bytes;

            return '';
        });
        try {
            $response = $this->response(
                new PreparedUpdate(true, 'debug', static fn(): Result => new Result('debug', '', 'signed')),
                $capture,
            );
            $output .= (string)$response->getBody();
        } finally {
            ob_end_clean();
            Configure::write('debug', $previous);
        }

        $this->assertStringContainsString('"type":"result"', $output);
        $this->assertStringNotContainsString('diagnostic', $output);
        $this->assertStringNotContainsString('development only', $output);
    }

    /**
     * Construct the real emission boundary with request-local session ownership.
     */
    protected function response(PreparedUpdate $update, OutputCapture $capture): Response
    {
        $request = new ServerRequest(['session' => new Session(['defaults' => 'php'])]);

        return new SseResponse()->create(
            $update,
            new RequestContext($request, 'default', '/_interact/update'),
            new Response(),
            $capture,
        );
    }
}
