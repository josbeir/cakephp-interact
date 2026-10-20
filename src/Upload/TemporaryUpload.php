<?php
declare(strict_types=1);

namespace Interact\Upload;

use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;

/**
 * Presents a staged file through the ordinary PSR uploaded-file contract.
 *
 * Moving it is an explicit application operation. A successful move releases the
 * staging record so subsequent renders cannot restore an already consumed file.
 */
class TemporaryUpload extends UploadedFile
{
    protected bool $consumed = false;

    /**
     * Open a private stream so moveTo() works after the original HTTP request.
     *
     * @param array{size: int, name: string, type: string} $metadata Stored display metadata.
     */
    public function __construct(
        public readonly string $id,
        string $path,
        array $metadata,
        protected readonly UploadStorageInterface $storage,
        protected readonly UploadOwner $owner,
    ) {
        parent::__construct(new Stream($path), $metadata['size'], UPLOAD_ERR_OK, $metadata['name'], $metadata['type']);
    }

    /**
     * Move into application-owned storage and invalidate the temporary reference only on success.
     */
    public function moveTo(string $targetPath): void
    {
        parent::moveTo($targetPath);
        $this->consumed = true;
        $this->storage->delete($this->id, $this->owner);
    }

    /**
     * Exclude consumed objects when publishing the next signed upload selection.
     */
    public function consumed(): bool
    {
        return $this->consumed;
    }

    /**
     * Return display metadata without disclosing storage paths or granting new access.
     *
     * @return array{id: string, name: string, size: int} Browser-visible file information.
     */
    public function metadata(): array
    {
        return ['id' => $this->id, 'name' => (string)$this->getClientFilename(), 'size' => (int)$this->getSize()];
    }
}
