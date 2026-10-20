<?php
declare(strict_types=1);

namespace Interact\Upload;

use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Runtime\SnapshotData;
use InvalidArgumentException;

/**
 * Publishes accepted upload metadata and releases selections cleared by application code.
 *
 * Template values remain uploaded-file objects; only reference identifiers enter snapshots.
 */
class UploadPublisher
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
     * Publish references and display metadata while releasing files explicitly removed by PHP.
     *
     * @return array{references: array<string, list<string>>, metadata: array<string, array{multiple: bool, maxSize: int, files: list<array{id: string, name: string, size: int}>}>, values: array<string, mixed>} Render and snapshot data.
     */
    public function export(
        Island $island,
        Definition $definition,
        SnapshotData $snapshot,
        IslandContext $context,
    ): array {
        $references = [];
        $metadata = [];
        $values = [];
        foreach ($definition->uploads as $name => $property) {
            $files = $property->files($island);
            $ids = [];
            foreach ($files as $file) {
                $stored = $this->storage->find($file->id, UploadOwner::fromContext($context, $snapshot, $name));
                if (!$stored instanceof TemporaryUpload || in_array($file->id, $ids, true)) {
                    throw new InvalidArgumentException('The selected file belongs to another upload field.');
                }

                $ids[] = $file->id;
            }

            foreach (array_diff($snapshot->uploads[$name] ?? [], $ids) as $removed) {
                $this->storage->delete($removed, UploadOwner::fromContext($context, $snapshot, $name));
            }

            $property->assign($island, $files);
            $references[$name] = $ids;
            $metadata[$name] = [
                'multiple' => $property->multiple,
                'maxSize' => UploadLimits::fileSize($this->config->maxFileSize),
                'files' => array_map(static fn(TemporaryUpload $file): array => $file->metadata(), $files),
            ];
            $values[$name] = $property->reflection->getValue($island);
        }

        return ['references' => $references, 'metadata' => $metadata, 'values' => $values];
    }
}
