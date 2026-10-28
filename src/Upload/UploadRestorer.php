<?php
declare(strict_types=1);

namespace Interact\Upload;

use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Runtime\SnapshotData;
use InvalidArgumentException;
use function Cake\I18n\__d;

/**
 * Restores signed selections and records unavailable files before application authorization.
 *
 * Session and field ownership are checked by storage before any file reaches an island.
 */
class UploadRestorer
{
    /**
     * Share private staging with the runtime and cleanup command.
     */
    public function __construct(
        protected readonly UploadStorageInterface $storage = new LocalUploadStorage(),
    ) {}

    /**
     * Restore available selections and report unavailable files without executing an action.
     *
     * @return array<string, list<string>> Field feedback for missing or expired files.
     */
    public function restore(
        Island $island,
        Definition $definition,
        SnapshotData $snapshot,
        IslandContext $context,
    ): array {
        if (array_diff_key($snapshot->uploads, $definition->uploads) !== []) {
            throw new InvalidArgumentException('Unknown signed upload field.');
        }

        $errors = [];
        foreach ($definition->uploads as $name => $property) {
            $files = [];
            foreach ($snapshot->uploads[$name] ?? [] as $id) {
                $file = $this->storage->find($id, UploadOwner::fromContext($context, $snapshot, $name));
                if (!$file instanceof TemporaryUpload) {
                    $errors[$name] = [__d(
                        'interact',
                        'A selected file is no longer available. Please select it again.',
                    )];
                } else {
                    $files[] = $file;
                }
            }

            if (!$property->multiple && count($files) > 1) {
                throw new InvalidArgumentException('Invalid single-file selection.');
            }

            $property->assign($island, $files);
        }

        return $errors;
    }
}
