<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Utility\Text;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateHandler;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Security\SnapshotCodec;
use Interact\View\TemplateView;
use Laminas\Diactoros\Stream;
use PHPUnit\Framework\TestCase;
use TestApp\Island\Counter;

/**
 * Verifies the shared HTTP service's response preservation and strict envelope boundaries.
 */
class UpdateHandlerTest extends TestCase
{
    public function testCustomResponseHeadersSurviveSuccessfulAndRejectedRequests(): void
    {
        $registry = new Registry(collection: 'explicit');
        $registry->register(Counter::class);

        $factory = new Factory($registry, new SnapshotCodec(str_repeat('k', 32)));
        $handler = new UpdateHandler($factory, new FailureResponder());
        $request = new ServerRequest(['url' => '/custom/update', 'environment' => ['REQUEST_METHOD' => 'POST']]);
        $request = $request->withParam('interactScope', 'admin');

        $mount = $factory->create(
            new TemplateView($request),
            new IslandContext($request, 'admin', '/custom/update'),
        )->mount('counter', [], 'counter', new IslandContext($request, 'admin', '/custom/update'));
        $response = new Response()->withHeader('X-Application', 'kept')
            ->withHeader('Content-Type', 'text/html')
            ->withHeader('Cache-Control', 'public');
        $result = $handler->handle($request->withParsedBody([
            'version' => 1,
            'snapshot' => $mount->snapshot,
            'action' => 'adjust',
            'args' => [1],
        ]), $response);
        static::assertSame(200, $result->getStatusCode());
        static::assertSame('kept', $result->getHeaderLine('X-Application'));
        static::assertSame('application/json', $result->getHeaderLine('Content-Type'));
        static::assertSame('no-store', $result->getHeaderLine('Cache-Control'));
        static::assertStringContainsString('"count":1', (string)$result->getBody());
        $failure = $handler->handle($request->withParsedBody(['version' => 2]), $response);
        static::assertSame(400, $failure->getStatusCode());
        static::assertSame('kept', $failure->getHeaderLine('X-Application'));
        static::assertStringContainsString('invalid_request', (string)$failure->getBody());
    }

    public function testDiscardedOversizedMultipartBodiesReceiveSpecificFeedback(): void
    {
        $handler = new UpdateHandler(
            new Factory(new Registry(collection: 'explicit'), new SnapshotCodec(str_repeat('k', 32))),
            new FailureResponder(),
        );
        $limit = Text::parseFileSize((string)ini_get('post_max_size'));
        $request = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]);
        $response = $handler->handle(
            $request->withHeader('Content-Type', 'multipart/form-data')
                ->withHeader('Content-Length', (string)($limit + 1))
                ->withParsedBody([]),
            new Response(),
        );
        static::assertSame($limit > 0 ? 413 : 400, $response->getStatusCode());
        static::assertStringContainsString(
            $limit > 0 ? 'upload_too_large' : 'invalid_request',
            (string)$response->getBody(),
        );
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testMalformedMultipartAndOversizedJsonFailWithoutRenderingHtml(): void
    {
        $handler = new UpdateHandler(
            new Factory(new Registry(collection: 'explicit'), new SnapshotCodec(str_repeat('k', 32))),
            new FailureResponder(),
        );
        $request = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]);
        foreach ([null, '{}', str_repeat('x', 1_048_577)] as $payload) {
            $result = $handler->handle($request->withHeader('Content-Type', 'multipart/form-data')
                ->withParsedBody(['payload' => $payload]), new Response());
            static::assertSame(400, $result->getStatusCode());
            static::assertSame('application/json', $result->getHeaderLine('Content-Type'));
        }

        $oversized = $request->withBody(new Stream('php://temp', 'w+'));
        $oversized->getBody()->write(str_repeat('x', 1_048_577));
        static::assertSame(400, $handler->handle($oversized, new Response())->getStatusCode());
        static::assertSame(405, $handler->handle($request->withMethod('GET'), new Response())->getStatusCode());
        static::assertSame(
            400,
            $handler->handle($request->withParam('interactScope', false)
                ->withParsedBody(['version' => 1]), new Response())
                ->getStatusCode(),
        );
    }
}
