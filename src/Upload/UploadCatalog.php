<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\I18n\DateTime;
use Cake\Utility\Fs\Path;

/**
 * Resolves and removes staged records only within their verified ownership boundary.
 */
class UploadCatalog
{
    /**
     * Share private storage settings with the staging backend.
     */
    public function __construct(protected readonly UploadConfig $config) {}

    /**
     * Read only a live record belonging to the exact signed field owner.
     */
    public function find(string $id, UploadOwner $owner, UploadStorageInterface $storage): ?TemporaryUpload
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            return null;
        }

        $directory = Path::join($this->config->directory, $owner->sessionKey());
        $record = new UploadRecordReader()->read(Path::join($directory, $id . '.json'));
        if (
            $record === null
            || $record['owner'] !== $owner->key()
            || $record['expires'] <= new DateTime()->getTimestamp()
            || !is_file(Path::join($directory, $id . '.bin'))
        ) {
            return null;
        }

        return new TemporaryUpload(
            $id,
            Path::join($directory, $id . '.bin'),
            ['size' => $record['size'], 'name' => $record['name'], 'type' => $record['type']],
            $storage,
            $owner,
        );
    }

    /**
     * Remove owned metadata and contents under the same session lock as staging.
     */
    public function delete(string $id, UploadOwner $owner): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            return;
        }

        new UploadFilesystem($this->config)->locked($owner, function (string $directory) use ($id, $owner): void {
            $record = new UploadRecordReader()->read(Path::join($directory, $id . '.json'));
            if ($record !== null && $record['owner'] === $owner->key()) {
                new UploadFilesystem($this->config)->remove(Path::join($directory, $id));
            }
        });
    }
}
