<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Rendering\RendererInterface;
use Interact\Security\SnapshotCodec;
use InvalidArgumentException;

/**
 * Turns an authorized island into native markup and its next signed browser state.
 *
 * Computed view variables stay outside the snapshot. Nested render frames are released
 * on failure, and response effects are emitted only when application validation succeeds.
 */
class HtmlRenderer
{
    /**
     * Bind native rendering, signing, and nested ownership for one request.
     */
    public function __construct(
        protected readonly RendererInterface $renderer,
        protected readonly SnapshotCodec $snapshots,
        protected readonly RenderStack $stack,
        protected readonly State $state = new State(),
    ) {
    }

    /**
     * Render one fresh island inside the shared, request-scoped ownership stack.
     */
    public function render(
        Island $island,
        Definition $definition,
        SnapshotData $identity,
        IslandContext $context,
    ): Result {
        $frame = $this->stack->push($identity->children);
        try {
            $state = $this->state->export($island, $definition);
            $data = $island->viewData();
            if (array_intersect_key($data, $state + ['errors' => true]) !== []) {
                throw new InvalidArgumentException('View data cannot shadow declared state, props, or errors.');
            }

            $content = $this->renderer->render($definition, $state + ['errors' => $island->errors()] + $data);
            $snapshot = $this->snapshots->seal(
                [
                    'alias' => $definition->declaration->name,
                    'id' => $identity->id,
                    'key' => $identity->key,
                    'state' => $state,
                    'children' => $frame->children,
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

            $attributes = [
                'x-data' => 'interact',
                'data-interact-id' => $identity->id,
                'data-interact-snapshot' => $snapshot,
                'data-interact-state' => json_encode((object)$state, JSON_THROW_ON_ERROR | JSON_HEX_TAG),
                'data-interact-writable' => json_encode((object)$writable, JSON_THROW_ON_ERROR),
                'data-interact-endpoint' => $context->endpoint,
                'data-interact-csrf' => (string)$context->request->getAttribute('csrfToken', ''),
            ];
            $tag = $definition->declaration->tag;
            $html = '<' . $tag;
            foreach ($attributes as $name => $value) {
                $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }

            $html .= '>' . $content . '</' . $tag . '>';
            $events = $island->errors() === [] ? $island->events() : [];
            Values::validate($events);

            return new Result($identity->id, $html, $snapshot, [
                'state' => $state,
                'writable' => $writable,
                'errors' => $island->errors(),
                'events' => $events,
            ]);
        } finally {
            $this->stack->pop();
        }
    }
}
