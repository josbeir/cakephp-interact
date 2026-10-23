<?php
declare(strict_types=1);

namespace Interact;

use Cake\Container\ContainerInterface;
use Cake\Controller\Controller;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Error\ExceptionTrap;
use Cake\Event\EventInterface;
use Cake\Event\EventManagerInterface;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use Cake\Utility\Security;
use Interact\Discovery\Registry;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateErrorListener;
use Interact\Http\UpdateHandler;
use Interact\Navigation\DocumentMiddleware;
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
     * Attach application rendering services without scanning island classes.
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        if ($this->isEnabled('events') && $app instanceof ContainerApplicationInterface) {
            $factory = $app->getContainer()->get(Factory::class);
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
        if (!$container->hasDefinition(FailureResponder::class)) {
            $container->addShared(FailureResponder::class);
        }

        if (!$container->hasDefinition(SseResponse::class)) {
            $container->addShared(SseResponse::class, static fn(): SseResponse => new SseResponse(
                $container->get(FailureResponder::class),
                new ExceptionTrap(Values::map(Configure::read('Error', []))),
            ));
        }

        if (!$container->hasDefinition(UpdateHandler::class)) {
            $container->add(UpdateHandler::class)->addArguments([
                Factory::class,
                FailureResponder::class,
                SseResponse::class,
            ]);
        }

        if (!$container->hasDefinition(UpdateErrorListener::class)) {
            $container->addShared(UpdateErrorListener::class)->addArgument(FailureResponder::class);
        }

        if (!$container->hasDefinition(Registry::class)) {
            $container->addShared(Registry::class, static fn(): Registry => new Registry($container));
        }

        if (!$container->hasDefinition(SnapshotCodec::class)) {
            $container->addShared(
                SnapshotCodec::class,
                static fn(): SnapshotCodec => new SnapshotCodec(Security::getSalt()),
            );
        }

        new UploadServices()->register($container);
        if (!$container->hasDefinition(Factory::class)) {
            $container->addShared(Factory::class)
                ->addArguments([Registry::class, SnapshotCodec::class, UploadManager::class]);
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
            '/_interact/update',
            [
                'plugin' => 'Interact',
                'controller' => 'Update',
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
