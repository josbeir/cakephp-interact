<?php
declare(strict_types=1);

namespace Interact\Realtime;

use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Interact\Configuration;
use Interact\Container\Services;
use Interact\Discovery\Registry;
use Interact\Realtime\Provider\MercureProvider;
use Interact\Realtime\Provider\MercureTokens;
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
        if (!Services::has($container, LeaseCodec::class)) {
            $container->addShared(LeaseCodec::class, static fn(): LeaseCodec => Services::get(
                $container,
                Configuration::class,
            )->leases());
        }

        if (Configure::read('Interact.realtime.provider') === 'mercure') {
            $this->mercure($container);
        }

        if (
            !Services::has($container, PublisherInterface::class)
            && Services::has($container, BroadcasterInterface::class)
        ) {
            $container->addShared(PublisherInterface::class, Publisher::class)
                ->addArgument(BroadcasterInterface::class);
        }

        if (!Services::has($container, Subscriptions::class)) {
            $container->addShared(Subscriptions::class, static fn(): Subscriptions => new Subscriptions(
                Services::get($container, LeaseCodec::class),
                Services::has($container, SubscriptionAuthorizerInterface::class),
            ));
        }

        if (
            !Services::has($container, SubscriptionHandler::class)
            && Services::has($container, SubscriptionAuthorizerInterface::class)
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
        if (!Services::has($container, TokenFactoryInterface::class)) {
            $container->addShared(TokenFactoryInterface::class, new MercureTokens()->create(...));
        }

        if (!Services::has($container, MercureProvider::class)) {
            if (!Services::has($container, MercurePublisher::class)) {
                throw new LogicException('Install josbeir/cakephp-mercure and load the Mercure plugin first.');
            }

            $container->addShared(MercureProvider::class, static function () use ($container): MercureProvider {
                $publisher = Services::get($container, MercurePublisher::class);
                /** @var mixed $url */
                $url = Configure::read('Mercure.public_url') ?: $publisher->getHubUrl();
                if (!is_string($url) || $url === '') {
                    throw new LogicException('Configure the public Mercure hub URL.');
                }

                return new MercureProvider($publisher, Services::get($container, TokenFactoryInterface::class), $url);
            });
        }

        foreach ([BroadcasterInterface::class, SubscriptionAuthorizerInterface::class] as $contract) {
            if (Services::has($container, $contract)) {
                continue;
            }

            $container->addShared($contract, static fn(): MercureProvider => Services::get(
                $container,
                MercureProvider::class,
            ));
        }
    }
}
