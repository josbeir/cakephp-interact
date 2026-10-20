<?php
declare(strict_types=1);

namespace Interact\Upload;

use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Runtime\SnapshotData;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use function Cake\I18n\__d;

/**
 * Applies explicitly declared transfer and removal operations after island authorization.
 */
class UploadMutator
{
    /**
     * Share private staging with the runtime and cleanup command.
     */
    public function __construct(
        protected readonly UploadStorageInterface $storage = new LocalUploadStorage(),
        protected readonly UploadConfig $config = new UploadConfig(),
    ) {
    }

    /**
     * Stage a file or remove a selected reference after authorization succeeds.
     *
     * @return string Changed upload field for the ordinary updated() hook.
     */
    public function apply(
        Island $island,
        Definition $definition,
        SnapshotData $snapshot,
        IslandContext $context,
        UploadOperation $operation,
    ): string {
        $name = $operation->field;
        $kind = $operation->kind;
        $property = $definition->uploads[$name] ?? throw new InvalidArgumentException('Unknown upload field.');
        $files = $property->files($island);
        if ($kind === 'stage') {
            $incoming = $context->request->getUploadedFile('file');
            if (
                !$incoming instanceof UploadedFileInterface
                || array_keys($context->request->getUploadedFiles()) !== ['file']
            ) {
                throw new InvalidArgumentException('An upload requires exactly one incoming file.');
            }

            if ($property->multiple && count($files) >= $property->fileCount($this->config)) {
                throw new UploadRejected(__d('interact', 'Remove an attachment before adding another file.'));
            }

            $previous = $files === [] ? null : $files[0];
            $file = $this->storage->put(
                $incoming,
                UploadOwner::fromContext($context, $snapshot, $name),
                $property->multiple ? null : $previous?->id,
                $property->fileSize($this->config),
                $property->fileCount($this->config),
            );
            $files = $property->multiple ? [...$files, $file] : [$file];
        } else {
            $id = $operation->id;
            if (
                $id !== null
                && !in_array($id, array_map(static fn(TemporaryUpload $file): string => $file->id, $files), true)
            ) {
                throw new InvalidArgumentException('This file is not selected.');
            }

            $files = $id === null
                ? []
                : array_values(array_filter($files, static fn(TemporaryUpload $file): bool => $file->id !== $id));
        }

        $property->assign($island, $files);

        return $name;
    }
}
