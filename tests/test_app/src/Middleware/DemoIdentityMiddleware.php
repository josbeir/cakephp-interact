<?php
declare(strict_types=1);

namespace TestApp\Middleware;

use Cake\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Supplies a test-only session identity so permission changes can be exercised without a login
 * application.
 */
class DemoIdentityMiddleware implements MiddlewareInterface
{
    /**
     * Production applications use their own authentication middleware instead of this fixture.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        assert($request instanceof ServerRequest, 'The configured collaborator must satisfy its declared contract.');
        /** @var mixed $role */
        $role = $request->getSession()->read('demo.role') ?? 'editor';

        return $handler->handle($request->withAttribute('identity', [
            'id' => $request->getSession()->read('demo.userId') === 2 ? 2 : 1,
            'role' => $role,
        ]));
    }
}
