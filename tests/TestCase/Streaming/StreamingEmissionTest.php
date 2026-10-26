<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Streaming;

use Cake\Error\ExceptionTrap;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Closure;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateHandler;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Runtime\PreparedUpdate;
use Interact\Runtime\Result;
use Interact\Runtime\Values;
use Interact\Security\SnapshotCodec;
use Interact\Streaming\RequestContext;
use Interact\Streaming\SseResponse;
use Interact\Test\Fixture\Streaming\StreamingIsland;
use Interact\TestSuite\IslandTest;
use LengthException;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Exercise ASCII, Unicode escaping, and HTML escaping at the encoded-data boundary.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function dataLimits(): iterable
    {
        foreach (['ASCII' => 'x', 'Unicode' => '€', 'HTML' => '<'] as $name => $unit) {
            yield $name . ' at limit' => [$unit, 0];
            yield $name . ' over limit' => [$unit, 1];
        }
    }

    /**
     * Measure JSON bytes before emission and return a safe failure for oversized output.
     *
     * @param int<0, 1> $extra Bytes beyond the permitted data size.
     */
    #[DataProvider('dataLimits')]
    public function testEncodedDataLimitIsCheckedBeforeWriting(string $unit, int $extra): void
    {
        $message = [
            'version' => 1,
            'id' => 'streaming',
            'type' => 'target',
            'to' => 'answer',
            'content' => '',
            'replace' => false,
            'html' => false,
        ];
        $flags = JSON_THROW_ON_ERROR | JSON_HEX_TAG;
        $available = 1_048_576 - strlen(json_encode($message, $flags));
        $width = strlen(json_encode($unit, $flags)) - 2;
        $copies = intdiv($available, $width);
        $padding = ($available % $width) + $extra;
        assert($copies >= 0 && $padding >= 0, 'The fixture envelope must leave room for boundary data.');
        $message['content'] = str_repeat($unit, $copies) . str_repeat('x', $padding);
        static::assertSame(1_048_576 + $extra, strlen(json_encode($message, $flags)));
        $update = new PreparedUpdate(true, 'streaming', static function (Closure $send) use ($message): Result {
            $send($message);

            return new Result('streaming', '<p>Done</p>', 'signed');
        });
        $logger = $this->createMock(ExceptionTrap::class);
        $logger->expects($extra === 0 ? static::never() : static::once())
            ->method('logException')
            ->with(static::isInstanceOf(LengthException::class), static::anything());
        $frames = $this->emit($update, $logger);
        static::assertCount($extra === 0 ? 2 : 1, $frames);
        $first = $this->decodeFrame($frames[0]);
        static::assertSame($extra === 0 ? 'target' : 'failure', $first['type']);
        if ($extra === 0) {
            static::assertSame("event: interact\ndata: " . json_encode($message, $flags) . "\n\n", $frames[0]);
            static::assertSame('result', $this->decodeFrame($frames[1])['type']);
        } else {
            static::assertSame(500, $first['status']);
            static::assertStringNotContainsString('1 MiB', $frames[0]);
        }
    }

    public function testOversizedTerminalResultBecomesASafeFailure(): void
    {
        $update = new PreparedUpdate(true, 'streaming', static fn(): Result => new Result(
            'streaming',
            str_repeat('x', 1_048_576),
            'signed',
        ));
        $logger = $this->createMock(ExceptionTrap::class);
        $logger->expects(static::once())
            ->method('logException')
            ->with(static::isInstanceOf(LengthException::class), static::anything());
        $frames = $this->emit($update, $logger);
        static::assertCount(1, $frames);
        $message = $this->decodeFrame($frames[0]);
        static::assertSame('failure', $message['type']);
        static::assertSame(500, $message['status']);
    }

    /**
     * Collect actual flushed frames without leaking output into PHPUnit.
     *
     * @return list<string>
     */
    protected function emit(PreparedUpdate $update, ExceptionTrap $logger): array
    {
        $context = new RequestContext(
            new ServerRequest([
                'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
            ]),
            'default',
            '/_interact/update',
        );
        $response = new SseResponse(exceptions: $logger)->create($update, $context, new Response());
        $frames = [];
        ob_start(static function (string $buffer) use (&$frames): string {
            if ($buffer !== '') {
                $frames[] = $buffer;
            }

            return '';
        });
        try {
            static::assertSame('', (string)$response->getBody());
        } finally {
            ob_end_clean();
        }

        return $frames;
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
