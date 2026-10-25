<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Closure;
use Interact\Composition\Relation;
use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\Subscriptions;
use Interact\Security\SnapshotCodec;
use Interact\Upload\UploadManager;

/**
 * Exports the same accepted state and signed identity for rendered and renderless updates.
 *
 * Only a real template render can change child ownership. Validation feedback is
 * recorded in signed metadata so a later successful action can clear rendered errors.
 */
class ResponseBuilder
{
    /**
     * Share signing, state, uploads, and the current native render scope.
     */
    public function __construct(
        protected readonly SnapshotCodec $snapshots,
        protected readonly State $state,
        protected readonly UploadManager $uploads,
        protected readonly RenderStack $stack,
        protected ?Subscriptions $subscriptions = null,
    ) {
    }

    /**
     * Enable subscription metadata for subsequent accepted responses.
     */
    public function attachSubscriptions(?Subscriptions $subscriptions): void
    {
        $this->subscriptions = $subscriptions;
    }

    /**
     * Export state before optional template evaluation, then sign the completed response.
     *
     * @param Closure(array<string, mixed>): string|null $render Null skips all view work.
     */
    public function build(
        Island $island,
        Definition $definition,
        SnapshotData $identity,
        IslandContext $context,
        ?Closure $render = null,
    ): Result {
        $state = $this->state->export($island, $definition);
        $uploads = $this->uploads->export($island, $definition, $identity, $context);
        $html = $render instanceof Closure ? $render($state + $uploads['values']) : '';
        $children = $render instanceof Closure ? $this->stack->parent()->children ?? [] : $identity->children;
        $snapshot = $this->snapshots->seal(
            [
                'alias' => $definition->declaration->name,
                'id' => $identity->id,
                'key' => $identity->key,
                'state' => $state,
                'routing' => $identity->routing->defaults,
                ...(
                    $identity->subscriptionEndpoint === null
                        ? []
                        : ['subscriptionEndpoint' => $identity->subscriptionEndpoint]
                ),
                ...($identity->relation instanceof Relation ? ['relation' => $identity->relation->data()] : []),
                ...($identity->pagePath === null ? [] : ['pagePath' => $identity->pagePath]),
                ...($identity->urlPrefix === null ? [] : ['urlPrefix' => $identity->urlPrefix]),
                'children' => $children,
                ...($uploads['references'] === [] ? [] : ['uploads' => $uploads['references']]),
                ...($island->errors() === [] ? [] : ['renderedErrors' => true]),
            ],
            $context->binding(),
            $context->scope,
        );
        $writable = [];
        foreach ($definition->properties as $name => $property) {
            if (!$property->writable) {
                continue;
            }

            $writable[$name] = ['type' => $property->type, 'nullable' => $property->nullable];
        }

        $values = [
            'realtime' => $this->subscriptions?->metadata($island, $definition, $identity, $context, $state),
            'url' => $this->state->urlMetadata($definition, $identity->urlPrefix),
            'state' => $state,
            'uploads' => $uploads['metadata'],
            'writable' => $writable,
            'errors' => $island->errors(),
            'events' => $island->errors() === [] ? $island->events() : [],
        ];
        Values::validate($values['events']);

        return $render instanceof Closure
            ? new Result($identity->id, $html, $snapshot, $values)
            : new RenderlessResult($identity->id, '', $snapshot, $values);
    }
}
