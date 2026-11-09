<?php
declare(strict_types=1);

namespace Interact\Test\TestSuite\Authorization;

use Cake\Http\ServerRequest;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs real middleware around a feature callback without replacing its service or policy behavior.
 */
class CallbackHandler implements RequestHandlerInterface
{
    /**
     * Receive the request-processing boundary used by the authorization test.
     *
     * @param Closure(ServerRequest): ResponseInterface $callback Native request handler.
     */
    public function __construct(protected readonly Closure $callback) {}

    /**
     * Pass the middleware's decorated request to the runtime callback.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        assert($request instanceof ServerRequest);

        return ($this->callback)($request);
    }
}
