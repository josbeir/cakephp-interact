<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\I18n\DateTime;
use Cake\Utility\Fs\Path;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use function Cake\I18n\__d;

/**
 * Coordinates bounded private staging, atomic quotas, and PSR file restoration.
 *
 * Filesystem collaborators share ownership and retention settings; a shared private
 * filesystem is required when requests are served by multiple application nodes.
 */
class LocalUploadStorage implements UploadStorageInterface
{
    /**
     * Receive the application's private directory, capacity, and retention settings.
     */
    public function __construct(protected readonly UploadConfig $config = new UploadConfig())
    {
    }

    /**
     * Serialize staging and capacity checks for one session before publishing a complete record.
     */
    public function put(
        UploadedFileInterface $file,
        UploadOwner $owner,
        ?string $replacingId = null,
        ?int $maxFileSize = null,
        ?int $maxFiles = null,
    ): TemporaryUpload {
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new UploadRejected(__d('interact', 'The file exceeds the server upload size limit.'));
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new UploadRejected(__d('interact', 'The file could not be uploaded. Check its size and try again.'));
        }

        $this->cleanup(10);

        return new UploadFilesystem($this->config)->locked($owner, function (string $directory) use (
            $file,
            $owner,
            $replacingId,
            $maxFileSize,
            $maxFiles,
        ): TemporaryUpload {
            $id = bin2hex(random_bytes(16));
            $path = Path::join($directory, $id . '.bin');
            try {
                $size = new UploadWriter($this->config)->copy($file, $path, $maxFileSize);
                new UploadQuota($this->config)->check($directory, $owner, $size, $replacingId, $maxFiles);
                $record = [
                    'owner' => $owner->key(),
                    'island' => $owner->islandKey(),
                    'expires' => new DateTime()->getTimestamp() + $this->config->ttl,
                    'size' => $size,
                    'name' => basename(str_replace('\\', '/', $file->getClientFilename() ?? 'upload')),
                    'type' => $file->getClientMediaType() ?? 'application/octet-stream',
                ];
                new UploadFilesystem($this->config)->write(Path::join($directory, $id . '.json'), $record);

                return new TemporaryUpload(
                    $id,
                    Path::join($directory, $id . '.bin'),
                    ['size' => $record['size'], 'name' => $record['name'], 'type' => $record['type']],
                    $this,
                    $owner,
                );
            } catch (Throwable $throwable) {
                if (is_file($path)) {
                    unlink($path);
                }

                throw $throwable;
            }
        });
    }

    /**
     * Restore a live record within its original ownership boundary.
     */
    public function find(string $id, UploadOwner $owner): ?TemporaryUpload
    {
        return new UploadCatalog($this->config)->find($id, $owner, $this);
    }

    /**
     * Release an owned record; repeated removal is harmless.
     */
    public function delete(string $id, UploadOwner $owner): void
    {
        new UploadCatalog($this->config)->delete($id, $owner);
    }

    /**
     * Remove expired records through the shared bounded cleanup workflow.
     */
    public function cleanup(int $limit = PHP_INT_MAX): int
    {
        return new UploadJanitor($this->config)->cleanup($limit);
    }
}
