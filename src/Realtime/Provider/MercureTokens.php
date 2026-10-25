<?php
declare(strict_types=1);

namespace Interact\Realtime\Provider;

use Cake\Core\Configure;
use Interact\Runtime\Values;
use LogicException;
use Mercure\Jwt\FirebaseTokenFactory;
use Mercure\Jwt\TokenFactoryInterface;
use ReflectionClass;

/**
 * Constructs the configured public Mercure token factory at the container boundary.
 *
 * Subscriber grants retain the plugin's issuer and audience defaults while using
 * explicit exact-topic permissions and the shorter subscription lease lifetime.
 */
class MercureTokens
{
    /**
     * Construct the configured public token factory with subscriber-only default claims.
     */
    public function create(): TokenFactoryInterface
    {
        $options = Values::map(Configure::read('Mercure.jwt', []));
        /** @var mixed $secret */
        $secret = $options['secret'] ?? '';
        /** @var mixed $algorithm */
        $algorithm = $options['algorithm'] ?? 'HS256';
        /** @var mixed $factory */
        $factory = $options['factory'] ?? FirebaseTokenFactory::class;
        if (
            !is_string($secret)
            || !is_string($algorithm)
            || !is_string($factory)
            || !is_a($factory, TokenFactoryInterface::class, true)
        ) {
            throw new LogicException('Invalid Mercure token factory configuration.');
        }

        $claims = Values::map($options['additional_claims'] ?? []);
        foreach ([
            'issuer' => 'iss',
            'audience' => 'aud',
            'subject' => 'sub',
            'client_id' => 'client_id',
        ] as $option => $claim) {
            if (!isset($options[$option]) || array_key_exists($claim, $claims)) {
                continue;
            }

            $claims[$claim] = $options[$option];
        }

        $claims += [
            'iss' => 'https://localhost',
            'aud' => Configure::read('Mercure.public_url') ?: Configure::read('Mercure.url'),
        ];

        return new ReflectionClass($factory)->newInstance($secret, $algorithm, $claims);
    }
}
