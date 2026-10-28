<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Http;

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

/**
 * Verifies the shared HTTP service's response preservation and strict envelope boundaries.
 */
class UpdateHandlerTest extends TestCase
{
    public function testCustomResponseHeadersSurviveSuccessfulAndRejectedRequests(): void
    {
        $registry = new Registry();

        $factory = new Factory($registry, new SnapshotCodec(str_repeat('k', 32)));
        $handler = new UpdateHandler($factory, new FailureResponder());
        $request = new ServerRequest(['url' => '/custom/update', 'environment' => ['REQUEST_METHOD' => 'POST']]);
        $request = $request->withParam('interactScope', 'admin');

        $mount = $factory->create(
            new TemplateView($request),
            new IslandContext($request, 'admin', '/custom/update'),
        )->mount('Counter', [], 'Counter', new IslandContext($request, 'admin', '/custom/update'));
        $response = new Response()->withHeader('X-Application', 'kept')
            ->withHeader('Content-Type', 'text/html')
            ->withHeader('Interact', 'false')
            ->withHeader('Cache-Control', 'public');
        $result = $handler->handle($request->withParsedBody([
            'version' => 1,
            'snapshot' => $mount->snapshot,
            'action' => 'adjust',
            'args' => [1],
        ]), $response);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('true', $result->getHeaderLine('Interact'));
        $this->assertSame('kept', $result->getHeaderLine('X-Application'));
        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $result->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('"count":1', (string)$result->getBody());
        $failure = $handler->handle($request->withParsedBody(['version' => 2]), $response);
        $this->assertSame(400, $failure->getStatusCode());
        $this->assertSame('true', $failure->getHeaderLine('Interact'));
        $this->assertSame('kept', $failure->getHeaderLine('X-Application'));
        $this->assertStringContainsString('invalid_request', (string)$failure->getBody());
    }

    public function testDiscardedOversizedMultipartBodiesReceiveSpecificFeedback(): void
    {
        $handler = new UpdateHandler(
            new Factory(new Registry(), new SnapshotCodec(str_repeat('k', 32))),
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
        $this->assertSame($limit > 0 ? 413 : 400, $response->getStatusCode());
        $this->assertStringContainsString(
            $limit > 0 ? 'upload_too_large' : 'invalid_request',
            (string)$response->getBody(),
        );
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testMalformedMultipartAndOversizedJsonFailWithoutRenderingHtml(): void
    {
        $handler = new UpdateHandler(
            new Factory(new Registry(), new SnapshotCodec(str_repeat('k', 32))),
            new FailureResponder(),
        );
        $request = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]);
        foreach ([null, '{}', str_repeat('x', 1_048_577)] as $payload) {
            $result = $handler->handle($request->withHeader('Content-Type', 'multipart/form-data')
                ->withParsedBody(['payload' => $payload]), new Response());
            $this->assertSame(400, $result->getStatusCode());
            $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        }

        $oversized = $request->withBody(new Stream('php://temp', 'w+'));
        $oversized->getBody()->write(str_repeat('x', 1_048_577));
        $this->assertSame(400, $handler->handle($oversized, new Response())->getStatusCode());
        $this->assertSame(405, $handler->handle($request->withMethod('GET'), new Response())->getStatusCode());
        $this->assertSame(
            400,
            $handler->handle($request->withParam('interactScope', false)
                ->withParsedBody(['version' => 1]), new Response())
                ->getStatusCode(),
        );
    }
}
