<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Action\RouteContext;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\Island;
use Interact\IslandContext;
use Interact\Loading\Initialization;
use Interact\Loading\MountRenderer;
use Interact\Loading\Options;
use Interact\Loading\PendingMount;
use Interact\Rendering\RendererInterface;
use Interact\Security\SnapshotCodec;
use Interact\Upload\UploadManager;
use Interact\Upload\UploadOperation;
use InvalidArgumentException;

/**
 * Coordinates initial mounts and verified updates through fresh island instances.
 *
 * Class resolution, state assignment, action validation, and rendering remain separate services.
 * Authorization runs before application hooks and actions; the runtime owns no persistent
 * application records or browser component store.
 */
class Runtime
{
    protected RenderStack $stack;

    protected ?string $pageQuery = null;

    protected ?RouteContext $pageRouting = null;

    protected HtmlRenderer $html;

    protected ActionInvoker $actions;

    protected Hydrator $hydrator;

    protected MountRenderer $mounts;

    /**
     * Fixed collaborators; mutable state is limited to the current render stack.
     */
    public function __construct(
        protected readonly Registry $registry,
        protected readonly SnapshotCodec $snapshots,
        protected readonly RendererInterface $renderer,
        protected readonly State $state = new State(),
        protected readonly UploadManager $uploads = new UploadManager(),
    ) {
        $this->stack = new RenderStack();
        $this->html = new HtmlRenderer(
            $renderer,
            $snapshots,
            $this->stack,
            $this->state,
            $this->uploads,
        );
        $this->actions = new ActionInvoker();
        $this->hydrator = new Hydrator($this->state);
        $this->mounts = new MountRenderer($this->html, $snapshots, $this->hydrator, $state);
    }

    /**
     * Construct, authorize, and render an island, or preserve an existing keyed child.
     *
     * @param array<string, mixed> $props Server-owned mount parameters; changing them requires a new child key.
     * @param string|Options|null $urlPrefix Existing URL prefix or complete mount loading options.
     */
    public function mount(
        string $alias,
        array $props,
        string $key,
        IslandContext $context,
        string|Options|null $urlPrefix = null,
    ): Result {
        $loading = Options::resolve($urlPrefix);
        $urlPrefix = $loading->urlPrefix;
        $parent = $this->stack->parent();
        $definition = $this->registry->definition($alias);
        $alias = $definition->declaration->name;
        $preserved = $this->stack->preserve($definition->declaration, $props, $key, $urlPrefix);
        if ($preserved instanceof Result) {
            return $preserved;
        }

        $island = $this->registry->create($definition);
        $this->hydrator->mount($island, $definition, $props);
        $this->state->initializeUrl(
            $island,
            $definition,
            $this->pageQuery ?? ($context->request->is('get') ? '?' . $context->request->getUri()->getQuery() : ''),
            $urlPrefix,
        );
        $island->authorize($context);
        $id = bin2hex(random_bytes(16));
        $identity = new SnapshotData([
            'alias' => $alias,
            'id' => $id,
            'key' => $key,
            'state' => [],
            'children' => [],
            'urlPrefix' => $urlPrefix,
            'routing' => ($this->pageRouting ?? RouteContext::fromRequest($context->request))->defaults,
        ]);
        $result = $this->mounts->render($island, $definition, $identity, $context, $loading);
        $childKey = 'key:' . $key;
        if ($parent instanceof RenderFrame) {
            $parent->children[$childKey] = [
                'id' => $id,
                'alias' => $alias,
                'props' => $props,
                ...($urlPrefix === null ? [] : ['urlPrefix' => $urlPrefix]),
            ];
        }

        return $result;
    }

    /**
     * Verify an update and run its authorized lifecycle on a fresh island instance.
     *
     * @param array<string, mixed> $request Validated HTTP envelope; all browser fields remain untrusted.
     */
    public function update(array $request, IslandContext $context): Result
    {
        $input = new UpdateRequest($request);
        $verified = $this->snapshots->open($input->snapshot, $context->binding(), $context->scope);
        if ($input->initialize) {
            return $this->initialize(new PendingMount($verified), $input, $context);
        }

        $data = new SnapshotData($verified);
        $definition = $this->registry->definition($data->alias);
        $this->actions->validate($definition, $input->action, $input->arguments);
        $island = $this->registry->create($definition);
        $this->hydrator->restore($island, $definition, $data->state);

        $previousRouting = $this->pageRouting;
        $this->pageRouting = $data->routing;
        $previous = $this->pageQuery;
        $this->pageQuery = $input->pageQuery;
        try {
            return $this->updateIsland($island, $definition, $data, $input, $context);
        } finally {
            $this->pageQuery = $previous;
            $this->pageRouting = $previousRouting;
        }
    }

    /**
     * Initialize a verified pending identity without allowing edits or application actions.
     */
    protected function initialize(PendingMount $pending, UpdateRequest $input, IslandContext $context): Result
    {
        Initialization::assertNoFiles($context->request);

        $data = $pending->identity;
        $definition = $this->registry->definition($data->alias);
        $island = $this->registry->create($definition);
        $this->hydrator->mount($island, $definition, $pending->props);
        $this->state->initializeUrl($island, $definition, $input->pageQuery, $data->urlPrefix);
        $island->authorize($context);
        $previousRouting = $this->pageRouting;
        $previousQuery = $this->pageQuery;
        $this->pageRouting = $data->routing;
        $this->pageQuery = $input->pageQuery;
        try {
            $island->mount();

            return $this->html->render($island, $definition, $data, $context);
        } finally {
            $this->pageRouting = $previousRouting;
            $this->pageQuery = $previousQuery;
        }
    }

    /**
     * Apply verified drafts and execute only after current access and upload availability checks.
     */
    protected function updateIsland(
        Island $island,
        Definition $definition,
        SnapshotData $data,
        UpdateRequest $input,
        IslandContext $context,
    ): Result {
        $restored = $input->urlRestore ? $this->state->restorationPaths($definition, $input->updates) : null;
        $paths = $this->state->apply($island, $definition, $input->updates);
        $uploadPaths = $this->authorizeUploads($island, $definition, $data, $input, $context);
        if ($uploadPaths === null) {
            return $this->html->render($island, $definition, $data, $context);
        }

        $paths = [...($restored ?? $paths), ...$uploadPaths];
        if ($paths !== []) {
            $island->updated($paths);
        }

        $outcome = $this->actions->invoke($definition, $island, $input);

        return $this->actions->respond(
            $island,
            $outcome,
            $data,
            $context,
            fn(): Result => $this->html->update($island, $definition, $data, $input, $context),
        );
    }

    /**
     * Check current access and return upload changes, or feedback that blocks application execution.
     *
     * @return list<string>|null Changed upload fields, or null when reselection is required.
     */
    protected function authorizeUploads(
        Island $island,
        Definition $definition,
        SnapshotData $data,
        UpdateRequest $input,
        IslandContext $context,
    ): ?array {
        $uploadErrors = $this->uploads->restore($island, $definition, $data, $context);
        $island->authorize($context);
        if ($uploadErrors !== []) {
            $island->setErrors($uploadErrors);

            return null;
        }

        if ($input->upload instanceof UploadOperation) {
            $field = $this->uploads->apply($island, $definition, $data, $context, $input->upload);
            if ($field === null) {
                return null;
            }

            return [$field];
        }

        if ($context->request->getUploadedFiles() !== []) {
            throw new InvalidArgumentException('Unexpected incoming upload.');
        }

        return [];
    }
}
