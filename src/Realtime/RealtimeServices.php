<?php
declare(strict_types=1);

namespace Interact\Realtime;

use Cake\Container\ContainerInterface;
use Cake\Core\Configure;
use Interact\Configuration;
use Interact\Discovery\Registry;
use LogicException;
use Mercure\Jwt\TokenFactoryInterface;
use Mercure\Service\PublisherInterface as MercurePublisher;

/**
 * Registers optional realtime behavior at CakePHP's explicit container boundary.
 *
 * Applications may replace the publishing and grant interfaces. Mercure classes are
 * resolved only when that integration is selected, leaving the dependency optional.
 */
class RealtimeServices
{
    /**
     * Register subscription signing/exporting and the selected provider's request handler.
     */
    public function register(ContainerInterface $container): void
    {
        if (!$container->hasDefinition(LeaseCodec::class)) {
            $container->addShared(LeaseCodec::class, static fn(): LeaseCodec => $container->get(
                Configuration::class,
            )->leases());
        }

        if (Configure::read('Interact.realtime.provider') === 'mercure') {
            $this->mercure($container);
        }

        if (
            !$container->hasDefinition(PublisherInterface::class)
            && $container->hasDefinition(BroadcasterInterface::class)
        ) {
            $container->addShared(PublisherInterface::class, Publisher::class)
                ->addArgument(BroadcasterInterface::class);
        }

        if (!$container->hasDefinition(Subscriptions::class)) {
            $container->addShared(Subscriptions::class, static fn(): Subscriptions => new Subscriptions(
                $container->get(LeaseCodec::class),
                $container->hasDefinition(SubscriptionAuthorizerInterface::class),
            ));
        }

        if (
            !$container->hasDefinition(SubscriptionHandler::class)
            && $container->hasDefinition(SubscriptionAuthorizerInterface::class)
        ) {
            $container->add(SubscriptionHandler::class)->addArguments([
                Registry::class,
                LeaseCodec::class,
                SubscriptionAuthorizerInterface::class,
                Subscriptions::class,
            ]);
        }
    }

    /**
     * Bridge public Mercure services without changing its cookies or provider configuration.
     */
    protected function mercure(ContainerInterface $container): void
    {
        if (!$container->hasDefinition(TokenFactoryInterface::class)) {
            $container->addShared(TokenFactoryInterface::class, new MercureTokens()->create(...));
        }

        if (!$container->hasDefinition(MercureProvider::class)) {
            if (!$container->hasDefinition(MercurePublisher::class)) {
                throw new LogicException('Install josbeir/cakephp-mercure and load the Mercure plugin first.');
            }

            $container->addShared(MercureProvider::class, static function () use ($container): MercureProvider {
                $publisher = $container->get(MercurePublisher::class);
                /** @var mixed $url */
                $url = Configure::read('Mercure.public_url') ?: $publisher->getHubUrl();
                if (!is_string($url) || $url === '') {
                    throw new LogicException('Configure the public Mercure hub URL.');
                }

                return new MercureProvider($publisher, $container->get(TokenFactoryInterface::class), $url);
            });
        }

        foreach ([BroadcasterInterface::class, SubscriptionAuthorizerInterface::class] as $contract) {
            if ($container->hasDefinition($contract)) {
                continue;
            }

            $container->addShared($contract, static fn(): MercureProvider => $container->get(
                MercureProvider::class,
            ));
        }
    }
}
