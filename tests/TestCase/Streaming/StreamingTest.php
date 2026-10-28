<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Streaming;

use Cake\Error\ExceptionTrap;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Action\ActionEffects;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateHandler;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Runtime\RenderlessResult;
use Interact\Security\SnapshotCodec;
use Interact\Streaming\RequestContext;
use Interact\Streaming\Stream;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\Streaming\CollectingSseResponse;
use Interact\Test\Fixture\Streaming\StreamingIsland;
use Interact\TestSuite\IslandTest;
use Interact\View\TemplateView;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies action streaming uses the authorized lifecycle and preserves the ordinary final result.
 */
class StreamingTest extends TestCase
{
    protected Factory $factory;

    protected IslandContext $context;

    /**
     * Assemble native views, real signing, and an isolated session for each interaction.
     */
    protected function setUp(): void
    {
        StreamingIsland::$allowed = true;
        StreamingIsland::$lifecycle = [];
        StreamingIsland::$afterChunk = null;
        $registry = new Registry();
        $registry->register(StreamingIsland::class);

        $this->factory = new Factory($registry, new SnapshotCodec(str_repeat('k', 32)));
        $request = new ServerRequest([
            'url' => '/_interact/update',
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);
        $this->context = new IslandContext($request, 'default', '/_interact/update');
    }

    public function testPreparationAuthorizesOnceAndDefersHooksAndAction(): void
    {
        $runtime = $this->factory->create(new TemplateView($this->context->request), $this->context);
        $mount = $runtime->mount('Streaming', [], null, $this->context);
        StreamingIsland::$lifecycle = [];
        $prepared = $runtime->prepare([
            'snapshot' => $mount->snapshot,
            'action' => 'generate',
            'updates' => ['answer' => 'edited'],
        ], $this->context);
        $this->assertTrue($prepared->stream);
        $this->assertSame(['authorize'], StreamingIsland::lifecycle());
        $messages = [];
        $result = $prepared->run(static function (array $message) use (&$messages): void {
            $messages[] = $message;
        });
        $this->assertSame(['authorize', 'updated', 'action'], StreamingIsland::lifecycle());
        $this->assertSame('Complete', $result->state['answer']);
        $this->assertStringContainsString('Complete', $result->html);
        $this->assertSame('<unsafe> €', $messages[1]['content']);
        $this->assertSame("<p>&lt;escaped&gt;</p>\n", $messages[2]['content']);
        $this->assertTrue($messages[2]['html']);
        /** @var mixed */
        $detail = $messages[3]['detail'];
        $this->assertIsArray($detail);
        $this->assertSame(50, $detail['percent']);
        $this->assertSame([['name' => 'finished', 'detail' => []]], $result->events);
        $this->expectException(LogicException::class);
        $prepared->run();
    }

    public function testApplicationHarnessCollectsIncrementalMessagesAndRenderlessResults(): void
    {
        $test = new IslandTest($this->factory, $this->context, 'Streaming');
        $test->call('generate')
            ->assertStreamed('answer', '<unsafe> €')
            ->assertStreamed('results', "<p>&lt;escaped&gt;</p>\n", html: true)
            ->assertStreamEvent('ready')
            ->assertSet('answer', 'Complete')
            ->assertDispatched('finished');
        $test->call('retain')->assertStreamed('answer', 'Retained', replace: true)->assertNotRendered();
        $this->assertInstanceOf(RenderlessResult::class, $test->result());
        $test->sync()->assertSee('Retained');
    }

    public function testStreamingCapabilityIsReleasedOnFailureAndUnavailableToHooks(): void
    {
        $effects = new ActionEffects();
        $stream = new Stream(
            static function (array $message): void {},
            new CallbackRenderer(static fn(): string => ''),
            new Definition(StreamingIsland::class),
        );
        $effects->capture(static function () use ($effects, $stream): void {
            static::assertSame($stream, $effects->stream());
        }, $stream);
        try {
            $effects->stream();
            $this->fail('Leaked capability');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $test = new IslandTest($this->factory, $this->context, 'Streaming');
        foreach ([
            static fn(): IslandTest => $test->call('ordinary'),
            static fn(): IslandTest => $test->set('answer', 'hook-stream'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Streaming outside action accepted');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('stream: true', $exception->getMessage());
            }
        }
    }

    public function testInvalidNamesDataAndUnsupportedElementsFailAtTheCapabilityBoundary(): void
    {
        $stream = new Stream(
            static function (array $message): void {},
            new CallbackRenderer(static fn(): string => ''),
            new Definition(StreamingIsland::class),
        );
        foreach ([
            static fn() => $stream->text('Bad name', '', false),
            static fn() => $stream->event('progress', ['object' => new stdClass()]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid stream accepted');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(LogicException::class);
        $stream->element('results', 'chunk', [], false);
    }

    public function testHttpNegotiatesDeferredStreamingAndClosesTheSessionBeforeChunks(): void
    {
        $test = new IslandTest($this->factory, $this->context, 'Streaming');
        $writer = new CollectingSseResponse();
        $handler = new UpdateHandler($this->factory, new FailureResponder(), $writer);
        $request = $this->context
            ->request
            ->withMethod('POST')
            ->withHeader('Accept', 'text/event-stream')
            ->withParsedBody(['version' => 1, 'snapshot' => $test->result()->snapshot, 'action' => 'generate']);
        StreamingIsland::$lifecycle = [];
        $response = $handler->handle($request, new Response()->withHeader('X-Application', 'kept')
            ->withHeader('Content-Length', '1'));
        $this->assertSame(['authorize'], StreamingIsland::lifecycle());
        $this->assertStringContainsString('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame('kept', $response->getHeaderLine('X-Application'));
        $this->assertSame('true', $response->getHeaderLine('Interact'));
        $this->assertFalse($response->hasHeader('Content-Length'));
        StreamingIsland::$afterChunk = function (): void {
            static::assertFalse($this->context->request->getSession()->started());
        };
        $this->assertSame('', (string)$response->getBody());
        $this->assertSame(6, count($writer->messages));
        $this->assertSame('result', $writer->messages[5]['type']);
        $this->assertSame(200, $writer->messages[5]['status']);
        /** @var mixed */
        $payload = $writer->messages[5]['payload'];
        $this->assertIsArray($payload);
        /** @var mixed */
        $state = $payload['state'];
        $this->assertInstanceOf(stdClass::class, $state);
        $this->assertSame('Complete', $state->answer);
        $this->assertFalse($request->getSession()->started());
        $this->assertSame('', (string)$response->getBody());
        $this->assertCount(6, $writer->messages);
    }

    public function testValidationAndOutcomesKeepTheirSemanticStatusInsideTheStream(): void
    {
        foreach ([
            ['generate', ['invalid'], 422],
            ['retain', [], 200],
            ['leave', [], 200],
            ['leave', ['download'], 200],
        ] as [
            $action,
            $arguments,
            $status,
        ]) {
            $test = new IslandTest($this->factory, $this->context, 'Streaming');
            $writer = new CollectingSseResponse();
            $request = $this->context
                ->request
                ->withMethod('POST')
                ->withHeader('Accept', 'text/event-stream')
                ->withParsedBody([
                    'version' => 1,
                    'snapshot' => $test->result()->snapshot,
                    'action' => $action,
                    'args' => $arguments,
                ]);
            $response = new UpdateHandler($this->factory, new FailureResponder(), $writer)->handle(
                $request,
                new Response(),
            );
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('', (string)$response->getBody());
            $last = $writer->messages[count($writer->messages) - 1];
            $this->assertSame('result', $last['type']);
            $this->assertSame($status, $last['status']);
            if ($status === 422) {
                /** @var mixed */
                $payload = $last['payload'];
                $this->assertIsArray($payload);
                $this->assertSame([], $payload['events']);
            }
        }
    }

    public function testFailuresBeforeEmissionRemainJsonAndActionExceptionsAreSafeTerminalFrames(): void
    {
        $test = new IslandTest($this->factory, $this->context, 'Streaming');
        $logger = $this->createMock(ExceptionTrap::class);
        $logger->expects(static::once())->method('logException');
        $writer = new CollectingSseResponse(exceptions: $logger);
        $handler = new UpdateHandler($this->factory, new FailureResponder(), $writer);
        $request = $this->context
            ->request
            ->withMethod('POST')
            ->withHeader('Accept', 'text/event-stream')
            ->withParsedBody([
                'version' => 1,
                'snapshot' => $test->result()->snapshot,
                'action' => 'generate',
                'args' => ['failure'],
            ]);
        $response = $handler->handle($request, new Response());
        $this->assertSame('', (string)$response->getBody());
        $last = $writer->messages[count($writer->messages) - 1];
        $this->assertSame('failure', $last['type']);
        $this->assertSame(500, $last['status']);
        $this->assertStringNotContainsString('Private diagnostic', json_encode($last, JSON_THROW_ON_ERROR));
        StreamingIsland::$allowed = false;
        $response = $handler->handle($request, new Response());
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        StreamingIsland::$allowed = true;
        $this->assertSame(
            400,
            $handler->handle($request->withParsedBody([
                'version' => 1,
                'snapshot' => $test->result()->snapshot,
                'action' => 'generate',
                'args' => [true],
            ]), new Response())
                ->getStatusCode(),
        );
    }

    public function testCapturedBindingDoesNotReopenClosedSession(): void
    {
        $context = new RequestContext($this->context->request, 'default', '/update');
        $binding = $context->binding();
        $context->release();
        $this->assertSame($binding, $context->binding());
        $this->assertFalse($context->request->getSession()->started());
    }

    public function testOrdinaryBindingFollowsSessionRenewalUntilEmissionFreezesIt(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects(static::exactly(2))->method('start');
        $session->expects(static::exactly(3))->method('id')->willReturn('original', 'renewed', 'renewed');
        $session->expects(static::once())->method('close');
        $context = new RequestContext(new ServerRequest(['session' => $session]), 'default', '/update');
        $this->assertSame('original', $context->binding());
        $this->assertSame('renewed', $context->binding());
        $context->release();
        $this->assertSame('renewed', $context->binding());
        $this->assertSame('renewed', $context->binding());
    }

    public function testJsonOnlyClientsReceiveTheFinalResultWithoutOpeningAStream(): void
    {
        $test = new IslandTest($this->factory, $this->context, 'Streaming');
        $writer = new CollectingSseResponse();
        $request = $this->context
            ->request
            ->withMethod('POST')
            ->withHeader('Accept', 'application/json')
            ->withParsedBody(['version' => 1, 'snapshot' => $test->result()->snapshot, 'action' => 'generate']);
        $response = new UpdateHandler($this->factory, new FailureResponder(), $writer)->handle(
            $request,
            new Response(),
        );
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Complete', (string)$response->getBody());
        $this->assertSame([], $writer->messages);
    }
}
