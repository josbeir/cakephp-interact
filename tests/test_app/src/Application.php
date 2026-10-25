<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Console\CommandCollection;
use Cake\Core\Configure;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use Interact\InteractPlugin;
use Mercure\MercurePlugin;
use TestApp\Command\ServerCommand;
use TestApp\Middleware\DemoIdentityMiddleware;
use TestApp\Middleware\DemoSecurityMiddleware;

/**
 * Boots the real CakePHP demo used for HTTP integration, browser acceptance, and installation
 * examples.
 */
class Application extends BaseApplication
{
    /**
     * Keep the demo's CSRF token separate from other applications on the same hostname.
     */
    public const string CSRF_COOKIE = 'interact_demo_csrf';

    /**
     * Load deterministic test configuration and the plugin through normal CakePHP hooks.
     */
    public function bootstrap(): void
    {
        parent::bootstrap();
        if (getenv('MERCURE_URL') && !$this->getPlugins()->has('Mercure')) {
            $this->addPlugin(MercurePlugin::class, ['bootstrap' => false]);
            Configure::write('Mercure', [
                'url' => getenv('MERCURE_URL'),
                'public_url' => getenv('MERCURE_PUBLIC_URL') ?: getenv('MERCURE_URL'),
                'jwt' => [
                    'secret' => getenv('MERCURE_JWT_SECRET'),
                    'issuer' => getenv('MERCURE_JWT_ISSUER') ?: 'https://localhost',
                    'audience' => getenv('MERCURE_JWT_AUDIENCE') ?: getenv('MERCURE_URL'),
                ],
            ]);
            Configure::write('Interact.realtime.provider', 'mercure');
        }

        if (!$this->getPlugins()->has('Interact')) {
            $this->addPlugin(InteractPlugin::class);
        }
    }

    /**
     * Use CakePHP's server workflow with the demo's bundled Mercure switch.
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return parent::console($commands)->add('server', ServerCommand::class);
    }

    /**
     * Keep CSRF enabled on island updates and derive fixture identity from the current session.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        /** @var mixed $errorConfig */
        $errorConfig = Configure::read('Error', []);
        assert(is_array($errorConfig), 'The demo error handler configuration must be an array.');

        return $middlewareQueue->add(new ErrorHandlerMiddleware($errorConfig, $this))
            ->add(new RoutingMiddleware($this))
            ->add(new DemoSecurityMiddleware())
            ->add(new BodyParserMiddleware())
            ->add(new CsrfProtectionMiddleware(['cookieName' => self::CSRF_COOKIE, 'httponly' => true]))
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
