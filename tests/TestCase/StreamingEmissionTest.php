<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateHandler;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Runtime\Values;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\StreamingIsland;
use Interact\TestSuite\IslandTest;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the real SSE writer emits complete frames before the action finishes.
 *
 * An output handler observes actual flushed bytes without leaking response output
 * into PHPUnit. This complements semantic assertions using the collecting sink.
 */
class StreamingEmissionTest extends TestCase
{
    public function testNativeWriterFlushesEscapedFramesBeforeTheTerminalResult(): void
    {
        StreamingIsland::$allowed = true;
        StreamingIsland::$lifecycle = [];
        StreamingIsland::$afterChunk = null;
        $registry = new Registry();
        $registry->register(StreamingIsland::class);

        $factory = new Factory($registry, new SnapshotCodec(str_repeat('k', 32)));
        $request = new ServerRequest([
            'url' => '/_interact/update',
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);
        $context = new IslandContext($request, 'default', '/_interact/update');
        $test = new IslandTest($factory, $context, 'Streaming');
        $response = new UpdateHandler($factory, new FailureResponder())->handle(
            $request->withMethod('POST')
                ->withHeader('Accept', 'text/event-stream')
                ->withParsedBody([
                    'version' => 1,
                    'snapshot' => $test->result()->snapshot,
                    'action' => 'generate',
                ]),
            new Response(),
        );
        /** @var list<string> $frames */
        $frames = [];
        $observed = null;
        StreamingIsland::$afterChunk = static function () use (&$frames, &$observed): void {
            $observed = count($frames);
        };
        ob_start(static function (string $buffer) use (&$frames): string {
            if ($buffer !== '') {
                $frames[] = $buffer;
            }

            return '';
        });
        try {
            $body = (string)$response->getBody();
        } finally {
            ob_end_clean();
            StreamingIsland::$afterChunk = null;
        }

        static::assertSame('', $body);
        static::assertSame(2, $observed, 'The action must observe its earlier frames already flushed.');
        static::assertCount(6, $frames);
        $messages = array_map($this->decodeFrame(...), $frames);
        foreach ($messages as $message) {
            static::assertSame(1, $message['version']);
            static::assertSame($test->result()->id, $message['id']);
        }

        static::assertSame(['target', 'target', 'target', 'event', 'event', 'result'], array_column($messages, 'type'));
        static::assertSame('', $messages[0]['content']);
        static::assertTrue($messages[0]['replace']);
        static::assertSame('<unsafe> €', $messages[1]['content']);
        static::assertStringContainsString('\\u003Cunsafe\\u003E', $frames[1]);
        static::assertSame("<p>&lt;escaped&gt;</p>\n", $messages[2]['content']);
        static::assertSame(200, $messages[5]['status']);
        /** @var mixed $payload */
        $payload = $messages[5]['payload'];
        static::assertIsArray($payload);
        /** @var mixed $state */
        $state = $payload['state'];
        static::assertIsArray($state);
        static::assertSame('Complete', $state['answer']);
    }

    /**
     * Require each flush to contain exactly one complete standard SSE event.
     *
     * @return array<string, mixed> Decoded versioned wire message.
     */
    protected function decodeFrame(string $frame): array
    {
        $prefix = "event: interact\ndata: ";
        static::assertStringStartsWith($prefix, $frame);
        static::assertStringEndsWith("\n\n", $frame);
        static::assertSame(1, substr_count($frame, "\n\n"));
        /** @var mixed $message */
        $message = json_decode(substr($frame, strlen($prefix), -2), true, flags: JSON_THROW_ON_ERROR);
        static::assertIsArray($message);

        return Values::map($message);
    }
}
