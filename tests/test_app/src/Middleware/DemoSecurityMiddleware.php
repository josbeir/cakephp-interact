<?php
declare(strict_types=1);

namespace TestApp\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exercises the demo under CSP and supplies request-local nonces on dedicated example routes.
 * HtmlHelper uses the same request attributes as CakePHP's optional CSP middleware.
 */
class DemoSecurityMiddleware implements MiddlewareInterface
{
    /**
     * Generate distinct script/style capabilities for each request to the nonce example.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $policy = "script-src 'self'; object-src 'none'; base-uri 'self'";
        if (str_starts_with($request->getUri()->getPath(), '/navigation/nonces')) {
            $script = base64_encode(random_bytes(24));
            $style = base64_encode(random_bytes(24));
            $request = $request->withAttribute('cspScriptNonce', $script)->withAttribute('cspStyleNonce', $style);
            $policy = "script-src 'nonce-{$script}'; style-src 'self' 'nonce-{$style}'; object-src 'none'; base-uri 'self'";
        }

        return $handler->handle($request)->withHeader('Content-Security-Policy', $policy);
    }
}
