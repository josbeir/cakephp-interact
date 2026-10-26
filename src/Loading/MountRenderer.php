<?php
declare(strict_types=1);

namespace Interact\Loading;

use Interact\Composition\Relation;
use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Navigation\Identity;
use Interact\Runtime\HtmlRenderer;
use Interact\Runtime\Hydrator;
use Interact\Runtime\Result;
use Interact\Runtime\SnapshotData;
use Interact\Runtime\State;
use Interact\Security\SnapshotCodec;

/**
 * Selects normal mounting or a signed, lightweight placeholder after initial authorization.
 *
 * Pending content exports only props and URL declarations, allowing mount-initialized state
 * to remain uninitialized until a verified initialization request arrives.
 */
class MountRenderer
{
    /**
     * Share signing, state, and native rendering with the request runtime.
     */
    public function __construct(
        protected readonly HtmlRenderer $html,
        protected readonly SnapshotCodec $snapshots,
        protected readonly Hydrator $hydrator,
        protected readonly State $state,
    ) {
    }

    /**
     * Run the mount hook immediately or return a mount-only browser capability.
     */
    public function render(
        Island $island,
        Definition $definition,
        SnapshotData $identity,
        IslandContext $context,
        Options $loading,
    ): Result {
        if ($loading->mode === 'eager') {
            $island->mount();

            return $this->html->render($island, $definition, $identity, $context);
        }

        $props = $this->hydrator->props($island, $definition);
        $bound = [];
        foreach (array_keys($identity->relation->models ?? []) as $name) {
            $bound[$name] = $definition->properties[$name]->read($island);
        }

        $snapshot = $this->snapshots->seal(
            [
                'pending' => true,
                'alias' => $identity->alias,
                'id' => $identity->id,
                'key' => $identity->key,
                'props' => $props,
                'bound' => $bound,
                ...($identity->relation instanceof Relation ? ['relation' => $identity->relation->data()] : []),
                'state' => [],
                'children' => [],
                'urlPrefix' => $identity->urlPrefix,
                'routing' => $identity->routing->defaults,
                ...(
                    $identity->subscriptionEndpoint === null
                        ? []
                        : ['subscriptionEndpoint' => $identity->subscriptionEndpoint]
                ),
                ...($identity->pagePath === null ? [] : ['pagePath' => $identity->pagePath]),
            ],
            $context->binding(),
            $context->scope,
        );
        $content = $this->html->placeholder($loading, $props);
        $result = new Result($identity->id, $content, $snapshot, [
            'url' => $this->state->urlMetadata($definition, $identity->urlPrefix),
        ]);

        return $this->html->wrap($definition, $result, $context, [
            'data-interact-loading' => $loading->mode,
            'data-interact-mount' => new Identity()->fingerprint($island, $definition, $identity, $context),
            ...(
                $identity->relation instanceof Relation
                    ? ['data-interact-relation' => json_encode($identity->relation->browserData(), JSON_THROW_ON_ERROR)]
                    : []
            ),
        ]);
    }
}
