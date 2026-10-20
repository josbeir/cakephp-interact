<?php
declare(strict_types=1);

namespace Interact\Upload;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use function Cake\I18n\__d;

/**
 * Copies incoming bytes with an enforced limit independent of reported file sizes.
 */
class UploadWriter
{
    /**
     * Share private storage settings with the staging backend.
     */
    public function __construct(protected readonly UploadConfig $config)
    {
    }

    /**
     * Bound bytes from the actual stream, including files with missing or inaccurate size metadata.
     *
     * @param int|null $maxFileSize Optional field ceiling, capped by global settings.
     */
    public function copy(UploadedFileInterface $file, string $path, ?int $maxFileSize = null): int
    {
        $target = fopen($path, 'xb');
        if ($target === false) {
            throw new RuntimeException('Cannot create temporary upload.');
        }

        chmod($path, 0o600);
        $limit = min($this->config->maxFileSize, $maxFileSize ?? $this->config->maxFileSize);
        try {
            $stream = $file->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $size = 0;
            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                $size += strlen($chunk);
                if ($size > $limit) {
                    throw new UploadRejected(__d('interact', 'This file exceeds the upload size limit.'));
                }

                if ($chunk !== '' && fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Cannot write temporary upload.');
                }
            }

            return $size;
        } finally {
            fclose($target);
        }
    }
}
