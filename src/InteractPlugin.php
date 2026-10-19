<?php
declare(strict_types=1);

namespace Interact;

use Cake\AttributeResolver\AttributeResolver;
use Cake\Container\ContainerInterface;
use Cake\Controller\Controller;
use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventManagerInterface;
use Cake\Routing\RouteBuilder;
use Cake\Utility\Security;
use Interact\Discovery\Registry;
use Interact\Runtime\Factory;
use Interact\Security\SnapshotCodec;

/**
 * Connects island discovery, dependency construction, routing, and view integration.
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
     * Configure application/plugin island discovery without overwriting application settings.
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        if (AttributeResolver::getConfig('interact') === null) {
            AttributeResolver::setConfig('interact', [
                'paths' => ['src/Island/*.php', 'src/Island/**/*.php'],
                'cache' => Configure::read('debug') ? false : '_cake_attributes_',
            ]);
        }

        if ($this->isEnabled('events') && $app instanceof ContainerApplicationInterface) {
            $factory = $app->getContainer()->get(Factory::class);
            assert($factory instanceof Factory, 'The configured collaborator must satisfy its declared contract.');
            $this->factory = $factory;
        }

        parent::bootstrap($app);
    }

    /**
     * Register replaceable services at the explicit construction boundary only.
     */
    public function services(ContainerInterface $container): void
    {
        if (!$container->hasDefinition(Registry::class)) {
            $container->addShared(Registry::class, static fn(): Registry => new Registry($container));
        }

        if (!$container->hasDefinition(SnapshotCodec::class)) {
            $container->addShared(
                SnapshotCodec::class,
                static fn(): SnapshotCodec => new SnapshotCodec(Security::getSalt()),
            );
        }

        if (!$container->hasDefinition(Factory::class)) {
            $container->addShared(Factory::class)->addArguments([Registry::class, SnapshotCodec::class]);
        }
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
