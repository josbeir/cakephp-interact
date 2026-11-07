<?php
declare(strict_types=1);

namespace Interact;

use Cake\Controller\Controller;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Error\ExceptionTrap;
use Cake\Event\EventInterface;
use Cake\Event\EventManagerInterface;
use Cake\Http\MiddlewareQueue;
use Cake\Http\ServerRequest;
use Cake\Routing\RouteBuilder;
use Interact\Container\Services;
use Interact\Debugging\Diagnostics;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateErrorListener;
use Interact\Http\UpdateHandler;
use Interact\Navigation\DocumentMiddleware;
use Interact\Realtime\RealtimeServices;
use Interact\Realtime\Subscriptions;
use Interact\Runtime\Factory;
use Interact\Runtime\Values;
use Interact\Security\SnapshotCodec;
use Interact\Streaming\SseResponse;
use Interact\Upload\UploadManager;
use Interact\Upload\UploadServices;

/**
 * Connects island class resolution, dependency construction, routing, and view integration.
 *
 * Application-provided service definitions take precedence. The update endpoint runs
 * through normal CakePHP middleware; page controllers receive the rendering factory
 * through view configuration rather than exposed template variables.
 */
class InteractPlugin extends BasePlugin
{
    protected ?string $name = 'Interact';

    protected ?Factory $factory = null;

    /**
     * Register request identification and attach rendering services without scanning island classes.
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        ServerRequest::addDetector('interact', ['header' => ['Interact' => 'true']]);

        if ($this->isEnabled('events') && $app instanceof ContainerApplicationInterface) {
            $factory = Services::get($app->getContainer(), Factory::class);
            assert($factory instanceof Factory, 'The configured collaborator must satisfy its declared contract.');
            $this->factory = $factory;
        }

        parent::bootstrap($app);
    }

    /**
     * Observe final document policies outside the application's request middleware.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue->prepend(new DocumentMiddleware());
    }

    /**
     * Register replaceable services at the explicit construction boundary only.
     */
    public function services(ContainerInterface $container): void
    {
        if (!Services::has($container, Configuration::class)) {
            $container->addShared(Configuration::class, Configuration::read(...));
        }

        if (!Services::has($container, FailureResponder::class)) {
            $container->addShared(FailureResponder::class);
        }

        if (!Services::has($container, Diagnostics::class)) {
            $container->addShared(Diagnostics::class)->addArgument(Configuration::class);
        }

        if (!Services::has($container, SseResponse::class)) {
            $container->addShared(SseResponse::class, static fn(): SseResponse => new SseResponse(
                Services::get($container, FailureResponder::class),
                new ExceptionTrap(Values::map(Configure::read('Error', []))),
            ));
        }

        if (!Services::has($container, UpdateHandler::class)) {
            $container->add(UpdateHandler::class)->addArguments([
                Factory::class,
                FailureResponder::class,
                SseResponse::class,
                Diagnostics::class,
            ]);
        }

        if (!Services::has($container, UpdateErrorListener::class)) {
            $container->addShared(UpdateErrorListener::class)
                ->addArguments([FailureResponder::class, Diagnostics::class]);
        }

        if (!Services::has($container, Registry::class)) {
            $container->addShared(Registry::class, static fn(): Registry => new Registry($container));
        }

        if (!Services::has($container, SnapshotCodec::class)) {
            $container->addShared(
                SnapshotCodec::class,
                static fn(): SnapshotCodec => Services::get($container, Configuration::class)->snapshots(),
            );
        }

        new UploadServices()->register($container);
        new RealtimeServices()->register($container);
        if (!Services::has($container, Factory::class)) {
            $container->addShared(Factory::class)
                ->addArguments([
                    Registry::class,
                    SnapshotCodec::class,
                    UploadManager::class,
                    Configuration::class,
                    Subscriptions::class,
                ]);
        }
    }

    /**
     * Resolve the update exception listener through the application's container.
     *
     * @return list<class-string<\Cake\Event\EventListenerInterface>>
     */
    public function eventListeners(): array
    {
        return [UpdateErrorListener::class];
    }

    /**
     * Applications may disable default routes and connect the same action under protected route scopes.
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->post(
            '/_interact/subscribe',
            [
                'plugin' => 'Interact',
                'controller' => 'Interact',
                'action' => 'subscribe',
                'interactScope' => 'default',
            ],
            'interact:subscribe',
        );

        $routes->post(
            '/_interact/update',
            [
                'plugin' => 'Interact',
                'controller' => 'Interact',
                'action' => 'update',
                'interactScope' => 'default',
            ],
            'interact:update',
        );
    }

    /**
     * Supply the factory through view options rather than leaking services into view variables.
     */
    public function events(EventManagerInterface $eventManager): EventManagerInterface
    {
        $factory = $this->factory;
        if ($factory instanceof Factory) {
            $eventManager->on('Controller.beforeRender', static function (EventInterface $event) use ($factory): void {
                $controller = $event->getSubject();
                if (
                    $controller instanceof Controller
                    && $controller->viewBuilder()->getOption('interactFactory') === null
                ) {
                    $controller->viewBuilder()->setOption('interactFactory', $factory);
                }
            });
        }

        return $eventManager;
    }
}
