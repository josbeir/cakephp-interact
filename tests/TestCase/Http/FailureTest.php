<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Http;

use Cake\Error\ExceptionTrap;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Http\Exception\HttpException;
use Cake\Http\Exception\InvalidCsrfTokenException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateErrorListener;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use Interact\Security\SnapshotFailureReason;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Verifies safe error classification and CakePHP exception-event isolation.
 *
 * Real signing and typed exceptions establish reasons without relying on message parsing.
 */
class FailureTest extends TestCase
{
    /**
     * Exercise each public server category, including nonstandard application HTTP statuses.
     *
     * @return iterable<string, array{Throwable, int, string}>
     */
    public static function failures(): iterable
    {
        yield 'invalid snapshot' => [new InvalidSnapshotException('private'), 409, 'invalid_snapshot'];
        yield 'expiry' => [
            new InvalidSnapshotException('private', reason: SnapshotFailureReason::Expired),
            409,
            'snapshot_expired',
        ];
        yield 'session' => [
            new InvalidSnapshotException('private', reason: SnapshotFailureReason::SessionChanged),
            409,
            'session_changed',
        ];
        yield 'invalid request' => [new InvalidArgumentException('private'), 400, 'invalid_request'];
        yield 'csrf' => [new InvalidCsrfTokenException('private'), 403, 'csrf_failed'];
        yield 'unauthenticated' => [new HttpException('private', 401), 401, 'unauthenticated'];
        yield 'forbidden' => [new HttpException('private', 403), 403, 'forbidden'];
        yield 'upload too large' => [new HttpException('private', 413), 413, 'upload_too_large'];
        yield 'bad request' => [new HttpException('private', 400), 400, 'invalid_request'];
        yield 'missing resource' => [new HttpException('private', 404), 404, 'http_error'];
        yield 'unavailable' => [new HttpException('private', 503), 503, 'server_error'];
        yield 'unexpected exception' => [new RuntimeException('private'), 500, 'server_error'];
    }

    #[DataProvider('failures')]
    public function testSafeFailureContract(Throwable $exception, int $status, string $code): void
    {
        $response = new FailureResponder()->fromException($exception);
        static::assertSame($status, $response->getStatusCode());
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        static::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        static::assertSame('true', $response->getHeaderLine('Interact'));
        $body = (string)$response->getBody();
        static::assertStringNotContainsString('private', $body);
        /** @var array{version: int, error: array{code: string, message: string}} $payload */
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        static::assertSame(1, $payload['version']);
        static::assertSame($code, $payload['error']['code']);
        static::assertNotSame('', $payload['error']['message']);
    }

    public function testHttpHeadersArePreserved(): void
    {
        $exception = new HttpException('private', 401);
        $exception->setHeader('WWW-Authenticate', 'Bearer');

        $response = new FailureResponder()->fromException($exception);
        static::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testExceptionListenerOnlyFormatsUnhandledUpdateRoutes(): void
    {
        $listener = new UpdateErrorListener(new FailureResponder());
        static::assertSame(['Exception.beforeRender' => 'render'], $listener->implementedEvents());
        $request = new ServerRequest(['params' => [
            'plugin' => 'Interact',
            'controller' => 'Interact',
            'action' => 'update',
            'interactScope' => 'admin',
        ]]);
        $event = new Event('Exception.beforeRender', data: [
            'request' => $request,
            'exception' => new RuntimeException('private'),
        ]);
        $listener->render($event);
        /** @var mixed $response */
        $response = $event->getResult();
        static::assertInstanceOf(Response::class, $response);
        static::assertSame(500, $response->getStatusCode());
        static::assertSame('true', $response->getHeaderLine('Interact'));
        $event->setResult(new Response(['status' => 418]));
        $listener->render($event);
        /** @var mixed $handled */
        $handled = $event->getResult();
        static::assertInstanceOf(Response::class, $handled);
        static::assertSame(418, $handled->getStatusCode());
        foreach ([
            [],
            ['request' => $request],
            ['request' => $request->withParam('interactScope', null), 'exception' => new RuntimeException()],
            ['request' => $request->withParam('interactScope', 42), 'exception' => new RuntimeException()],
            ['request' => $request->withParam('interactScope', false), 'exception' => new RuntimeException()],
            [
                'request' => $request->withParam('interactScope', null)->withHeader('Interact', 'true'),
                'exception' => new RuntimeException(),
            ],
        ] as $data) {
            $ignored = new Event('Exception.beforeRender', data: $data);
            $listener->render($ignored);
            static::assertNull($ignored->getResult());
        }
    }

    public function testContextIsCheckedBeforeExpiryAndOnlyAfterSignatureVerification(): void
    {
        $time = 10;
        $codec = new SnapshotCodec(str_repeat('s', 32), 1, static function () use (&$time): int {
            return $time;
        });
        $token = $codec->seal([], 'original', 'admin');
        $time = 12;
        foreach ([
            [$token, 'original', 'admin', SnapshotFailureReason::Expired],
            [$token, 'other', 'admin', SnapshotFailureReason::SessionChanged],
            [$token, 'other', 'public', SnapshotFailureReason::Invalid],
            [$token . 'x', 'other', 'admin', SnapshotFailureReason::Invalid],
        ] as [$snapshot, $binding, $scope, $reason]) {
            try {
                $codec->open($snapshot, $binding, $scope);
                static::fail('An invalid snapshot was accepted.');
            } catch (InvalidSnapshotException $exception) {
                static::assertSame($reason, $exception->reason);
            }
        }
    }

    public function testExceptionFormattingPreservesCakeLogging(): void
    {
        $request = new ServerRequest(['params' => [
            'plugin' => 'Interact',
            'controller' => 'Interact',
            'action' => 'update',
            'interactScope' => 'default',
        ]]);
        $exception = new RuntimeException('Private diagnostic');
        $trap = $this->createMock(ExceptionTrap::class);
        $trap->expects($this->once())->method('logException')->with($exception, $request);
        $trap->expects($this->never())->method('renderer');
        $events = new EventManager();
        $events->on(new UpdateErrorListener(new FailureResponder()));

        $middleware = new ErrorHandlerMiddleware($trap);
        $middleware->setEventManager($events);

        $response = $middleware->handleException($exception, $request);
        static::assertSame(500, $response->getStatusCode());
        static::assertStringContainsString('server_error', (string)$response->getBody());
        static::assertStringNotContainsString('Private diagnostic', (string)$response->getBody());
    }
}
