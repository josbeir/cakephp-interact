<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Composition\Relation;
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
    protected ResponseBuilder $responses;

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
        $this->responses = new ResponseBuilder($snapshots, $state, $uploads, $stack);
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
        $this->stack->push($identity->children, $island, $definition, $identity, $context);
        try {
            $data = $island->viewData();
            $result = $this->responses->build(
                $island,
                $definition,
                $identity,
                $context,
                function (array $values) use ($island, $definition, $data): string {
                    if (array_intersect_key($data, $values + ['errors' => true]) !== []) {
                        throw new InvalidArgumentException('View data cannot shadow declared state, props, or errors.');
                    }

                    return $this->renderer->render($definition, $values + ['errors' => $island->errors()] + $data);
                },
            );

            return $this->wrap($definition, $result, $context, [
                'data-interact-mount' => new Identity()->fingerprint($island, $definition, $identity, $context),
                ...(
                    $identity->relation instanceof Relation
                        ? ['data-interact-relation' => json_encode($identity->relation->data(), JSON_THROW_ON_ERROR)]
                        : []
                ),
            ]);
        } finally {
            $this->stack->pop();
        }
    }

    /**
     * Honor action rendering policy after preserving current and previously rendered feedback.
     */
    public function update(
        Island $island,
        Definition $definition,
        SnapshotData $data,
        UpdateRequest $input,
        IslandContext $context,
    ): Result {
        if (new RenderPolicy()->skips($island, $definition, $data, $input->action)) {
            return $this->renderless($island, $definition, $data, $context);
        }

        return $this->render($island, $definition, $data, $context);
    }

    /**
     * Return accepted state without evaluating view data or changing child ownership.
     */
    public function renderless(
        Island $island,
        Definition $definition,
        SnapshotData $identity,
        IslandContext $context,
    ): Result {
        return $this->responses->build($island, $definition, $identity, $context);
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
