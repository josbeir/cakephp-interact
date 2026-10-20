<?php
declare(strict_types=1);

namespace Interact\Upload;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Defines private staging independently of transport and permanent application storage.
 *
 * Implementations enforce ownership, expiry, and quotas atomically across requests.
 * Retrieved files must remain compatible with CakePHP's uploaded-file validators.
 */
interface UploadStorageInterface
{
    /**
     * Retain an incoming file under its verified ownership boundary.
     *
     * @param string|null $replacingId Existing selected file excluded from the field slot count; bytes still count until removal.
     *
     * @throws UploadRejected For upload errors or exceeded capacity.
     */
    public function put(UploadedFileInterface $file, UploadOwner $owner, ?string $replacingId = null): TemporaryUpload;

    /**
     * Restore an available file only for its original owner.
     */
    public function find(string $id, UploadOwner $owner): ?TemporaryUpload;

    /**
     * Release an owned file; repeated removal is harmless.
     */
    public function delete(string $id, UploadOwner $owner): void;

    /**
     * Remove at most the requested number of expired records and return the removal count.
     */
    public function cleanup(int $limit = PHP_INT_MAX): int;
}
