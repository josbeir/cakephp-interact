<?php
declare(strict_types=1);

namespace Interact\Navigation;

use Cake\Utility\Security;
use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Runtime\Hydrator;
use Interact\Runtime\SnapshotData;

/**
 * Identifies compatible layout mounts without publishing props or session identifiers again.
 *
 * This fingerprint guides DOM reuse only. Every destination mount and every later
 * update still runs normal server authorization against its own signed identity.
 */
class Identity
{
    /**
     * Exclude mutable drafts and originating routes while retaining server-owned mount identity.
     */
    public function fingerprint(
        Island $island,
        Definition $definition,
        SnapshotData $identity,
        IslandContext $context,
    ): string {
        $values = [
            $identity->alias,
            $identity->key,
            new Hydrator()->props($island, $definition),
            $context->scope,
            $context->endpoint,
            $identity->urlPrefix,
            $context->binding(),
            ...($identity->subscriptionEndpoint === null ? [] : [$identity->subscriptionEndpoint]),
        ];

        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR), Security::getSalt());
    }
}
