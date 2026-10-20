<?php
declare(strict_types=1);

namespace Interact\Upload;

use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Runtime\SnapshotData;

/**
 * Coordinates upload restoration, authorized changes, and publication through focused services.
 *
 * Files remain request-local objects; this facade gives the rendering runtime one
 * injected collaborator without introducing an application persistence lifecycle.
 */
class UploadManager
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
     * Restore available selections and expose expired files as ordinary field feedback.
     *
     * @return array<string, list<string>> Missing-file messages.
     */
    public function restore(
        Island $island,
        Definition $definition,
        SnapshotData $snapshot,
        IslandContext $context,
    ): array {
        return new UploadRestorer($this->storage)->restore($island, $definition, $snapshot, $context);
    }

    /**
     * Apply one explicit operation after application access has been checked.
     */
    public function apply(
        Island $island,
        Definition $definition,
        SnapshotData $snapshot,
        IslandContext $context,
        UploadOperation $operation,
    ): ?string {
        try {
            return new UploadMutator($this->storage, $this->config)->apply(
                $island,
                $definition,
                $snapshot,
                $context,
                $operation,
            );
        } catch (UploadRejected $uploadRejected) {
            $island->setErrors([$operation->field => [$uploadRejected->getMessage()]]);

            return null;
        }
    }

    /**
     * Collect references, browser metadata, and server-only template values.
     *
     * @return array{references: array<string, list<string>>, metadata: array<string, array{multiple: bool, maxSize: int, files: list<array{id: string, name: string, size: int}>}>, values: array<string, mixed>} Accepted selections.
     */
    public function export(
        Island $island,
        Definition $definition,
        SnapshotData $snapshot,
        IslandContext $context,
    ): array {
        return new UploadPublisher($this->storage, $this->config)->export($island, $definition, $snapshot, $context);
    }
}
