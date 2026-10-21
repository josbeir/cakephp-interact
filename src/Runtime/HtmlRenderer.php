<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Loading\PlaceholderRenderer;
use Interact\Navigation\Identity;
use Interact\Rendering\PlaceholderRendererInterface;
use Interact\Rendering\RendererInterface;
use Interact\Security\SnapshotCodec;
use Interact\Upload\UploadManager;
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
        protected readonly UploadManager $uploads = new UploadManager(),
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
            $uploads = $this->uploads->export($island, $definition, $identity, $context);
            if (array_intersect_key($data, $state + $uploads['values'] + ['errors' => true]) !== []) {
                throw new InvalidArgumentException('View data cannot shadow declared state, props, or errors.');
            }

            $content = $this->renderer->render(
                $definition,
                $state + $uploads['values'] + ['errors' => $island->errors()] + $data,
            );
            $snapshot = $this->snapshots->seal(
                [
                    'alias' => $definition->declaration->name,
                    'id' => $identity->id,
                    'key' => $identity->key,
                    'state' => $state,
                    'routing' => $identity->routing->defaults,
                    ...($identity->urlPrefix === null ? [] : ['urlPrefix' => $identity->urlPrefix]),
                    'children' => $frame->children,
                    ...($uploads['references'] === [] ? [] : ['uploads' => $uploads['references']]),
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
                'url' => $this->state->urlMetadata($definition, $identity->urlPrefix),
                'state' => $state,
                'uploads' => $uploads['metadata'],
                'writable' => $writable,
                'errors' => $island->errors(),
                'events' => $island->errors() === [] ? $island->events() : [],
            ];
            Values::validate($values['events']);

            return $this->wrap($definition, new Result($identity->id, $content, $snapshot, $values), $context, [
                'data-interact-mount' => new Identity()->fingerprint($island, $definition, $identity, $context),
            ]);
        } finally {
            $this->stack->pop();
        }
    }

    /**
     * Render lightweight content without allowing nested mounts to escape its pending identity.
     *
     * @param array<string, mixed> $props Validated declared props, including defaults.
     */
    public function placeholder(Options $loading, array $props): string
    {
        return $this->stack->placeholder(fn(): string => $this->renderer instanceof PlaceholderRendererInterface
            ? $this->renderer->placeholder($loading, $props)
            : new PlaceholderRenderer()->render($loading, $props));
    }

    /**
     * Wrap content in its declared root with escaped runtime-owned metadata.
     *
     * @param array<string, string> $extra Additional generated bootstrap attributes.
     */
    public function wrap(Definition $definition, Result $result, IslandContext $context, array $extra = []): Result
    {
        $html = '<' . $definition->declaration->tag;
        foreach ($this->attributes($result, $context) + $extra as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        $html .= '>' . $result->html . '</' . $definition->declaration->tag . '>';

        return new Result($result->id, $html, $result->snapshot, [
            'state' => $result->state,
            'writable' => $result->writable,
            'url' => $result->url,
            'uploads' => $result->uploads,
            'errors' => $result->errors,
            'events' => $result->events,
        ]);
    }

    /**
     * Encode generated browser metadata without exposing request-local file objects.
     *
     * @return array<string, string> Escaped by the root-markup builder.
     */
    protected function attributes(Result $result, IslandContext $context): array
    {
        $attributes = [
            'x-data' => 'interact',
            'data-interact-id' => $result->id,
            'data-interact-snapshot' => $result->snapshot,
            'data-interact-state' => json_encode((object)$result->state, JSON_THROW_ON_ERROR | JSON_HEX_TAG),
            'data-interact-writable' => json_encode((object)$result->writable, JSON_THROW_ON_ERROR),
            'data-interact-endpoint' => $context->endpoint,
            'data-interact-csrf' => (string)$context->request->getAttribute('csrfToken', ''),
        ];
        if ($result->url !== []) {
            $attributes['data-interact-url'] = json_encode((object)$result->url, JSON_THROW_ON_ERROR | JSON_HEX_TAG);
        }

        if ($result->uploads !== []) {
            $attributes['data-interact-uploads'] = json_encode(
                $result->uploads,
                JSON_THROW_ON_ERROR | JSON_HEX_TAG,
            );
        }

        return $attributes;
    }
}
