<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\Island;
use Interact\IslandContext;
use Interact\Rendering\RendererInterface;
use Interact\Security\SnapshotCodec;
use Interact\Upload\UploadManager;
use Interact\Upload\UploadOperation;
use InvalidArgumentException;

/**
 * Coordinates initial mounts and verified updates through fresh island instances.
 *
 * Discovery, state assignment, action validation, and rendering remain separate services.
 * Authorization runs before application hooks and actions; the runtime owns no persistent
 * application records or browser component store.
 */
class Runtime
{
    protected RenderStack $stack;

    protected HtmlRenderer $html;

    protected ActionInvoker $actions;

    protected Hydrator $hydrator;

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
        $this->html = new HtmlRenderer($renderer, $snapshots, $this->stack, $this->state, $this->uploads);
        $this->actions = new ActionInvoker();
        $this->hydrator = new Hydrator($this->state);
    }

    /**
     * Construct, authorize, and render an island, or preserve an existing keyed child.
     *
     * @param array<string, mixed> $props Server-owned mount parameters; changing them requires a new child key.
     */
    public function mount(string $alias, array $props, string $key, IslandContext $context): Result
    {
        $parent = $this->stack->parent();
        $definition = $this->registry->definition($alias);
        $preserved = $this->stack->preserve($definition->declaration, $props, $key);
        if ($preserved instanceof Result) {
            return $preserved;
        }

        $island = $this->registry->create($definition);
        $this->hydrator->mount($island, $definition, $props);
        $island->authorize($context);
        $island->mount();

        $id = bin2hex(random_bytes(16));
        $identity = new SnapshotData(['alias' => $alias, 'id' => $id, 'key' => $key, 'state' => [], 'children' => []]);
        $result = $this->html->render($island, $definition, $identity, $context);
        $childKey = 'key:' . $key;
        if ($parent instanceof RenderFrame) {
            $parent->children[$childKey] = ['id' => $id, 'alias' => $alias, 'props' => $props];
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
        $data = new SnapshotData($this->snapshots->open($input->snapshot, $context->binding(), $context->scope));
        $definition = $this->registry->definition($data->alias);
        $this->actions->validate($definition, $input->action, $input->arguments);
        $island = $this->registry->create($definition);
        $this->hydrator->restore($island, $definition, $data->state);

        return $this->updateIsland($island, $definition, $data, $input, $context);
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
        $paths = $this->state->apply($island, $definition, $input->updates);
        $uploadPaths = $this->authorizeUploads($island, $definition, $data, $input, $context);
        if ($uploadPaths === null) {
            return $this->html->render($island, $definition, $data, $context);
        }

        $paths = [...$paths, ...$uploadPaths];
        if ($paths !== []) {
            $island->updated($paths);
        }

        $this->actions->invoke($definition, $island, $input);

        return $this->html->render($island, $definition, $data, $context);
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
