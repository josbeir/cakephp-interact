<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use Interact\InteractPlugin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TestApp\Middleware\DemoIdentityMiddleware;

/**
 * Boots the real CakePHP demo used for HTTP integration, browser acceptance, and installation
 * examples.
 */
class Application extends BaseApplication
{
    /**
     * Load deterministic test configuration and the plugin through normal CakePHP hooks.
     */
    public function bootstrap(): void
    {
        parent::bootstrap();
        if (!$this->getPlugins()->has('Interact')) {
            $this->addPlugin(new InteractPlugin(['path' => (string)ROOT . '/']));
        }
    }

    /**
     * Keep CSRF enabled on island updates and derive fixture identity from the current session.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue->add(new RoutingMiddleware($this))
            ->add(
                static fn(
                    ServerRequestInterface $request,
                    RequestHandlerInterface $handler,
                ): ResponseInterface => $handler->handle($request)->withHeader(
                    'Content-Security-Policy',
                    "script-src 'self'; object-src 'none'; base-uri 'self'",
                ),
            )
            ->add(new BodyParserMiddleware())
            ->add(new CsrfProtectionMiddleware(['httponly' => true]))
            ->add(new DemoIdentityMiddleware());
    }

    /**
     * Discover demo controller routes from CakePHP attributes; the plugin owns its update endpoint.
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->connectAttributes('interactDemoRoutes');
    }
}
