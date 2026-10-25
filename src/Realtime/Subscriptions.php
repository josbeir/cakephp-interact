<?php
declare(strict_types=1);

namespace Interact\Realtime;

use Cake\Routing\Router;
use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Runtime\SnapshotData;
use InvalidArgumentException;
use LogicException;

/**
 * Exports authorized declarations and subscription-only restoration data after island behavior.
 *
 * Only accepted props/state and routing context enter leases. Upload references,
 * child capabilities, and normal snapshots remain in their existing lifecycle.
 */
class Subscriptions
{
    /**
     * Share the lease signer and explicit provider availability with render callers.
     */
    public function __construct(protected readonly LeaseCodec $leases, protected readonly bool $enabled = true)
    {
    }

    /**
     * Validate request-local declarations before exposing browser routing metadata.
     *
     * @return list<array{topic: string, mode: string, event: string|null, private: bool}>
     */
    public function declarations(Island $island, IslandContext $context): array
    {
        /** @var array<array-key, mixed> $subscriptions */
        $subscriptions = $island->subscriptions($context);
        if (!array_is_list($subscriptions) || count($subscriptions) > 128) {
            throw new InvalidArgumentException('Use a list of at most 128 realtime subscriptions per island.');
        }

        $result = [];
        /** @var mixed $subscription */
        foreach ($subscriptions as $subscription) {
            if (!$subscription instanceof Subscription) {
                throw new InvalidArgumentException('Island subscriptions must be Subscription declarations.');
            }

            $result[] = $subscription->data();
        }

        return $result;
    }

    /**
     * Sign accepted state separately from ordinary request credentials.
     *
     * @param array<string, mixed> $state Accepted declared props/state, never local browser edits.
     * @return array{lease: string, endpoint: string, subscriptions: list<array{topic: string, mode: string, event: string|null, private: bool}>}|null Browser metadata, absent for islands without subscriptions.
     */
    public function metadata(
        Island $island,
        Definition $definition,
        SnapshotData $identity,
        IslandContext $context,
        array $state,
    ): ?array {
        $subscriptions = $this->declarations($island, $context);
        if ($subscriptions === []) {
            return null;
        }

        if (!$this->enabled) {
            throw new LogicException('Configure a realtime provider before declaring island subscriptions.');
        }

        return [
            'lease' => $this->leases->seal(
                [
                    'alias' => $definition->declaration->name,
                    'id' => $identity->id,
                    'state' => $state,
                    'routing' => $identity->routing->defaults,
                    'endpoint' => $context->endpoint,
                ],
                $context->binding(),
                $context->scope,
            ),
            'endpoint' => $context->subscriptionEndpoint ?? Router::url(['_name' => 'interact:subscribe']),
            'subscriptions' => $subscriptions,
        ];
    }
}
