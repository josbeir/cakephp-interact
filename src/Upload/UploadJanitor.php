<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\I18n\DateTime;
use Cake\Utility\Fs\Finder;
use Cake\Utility\Fs\Path;
use RuntimeException;

/**
 * Prunes expired private records without following symlinks or disrupting active transfers.
 */
class UploadJanitor
{
    /**
     * Share private storage settings with the staging backend.
     */
    public function __construct(protected readonly UploadConfig $config)
    {
    }

    /**
     * Delete expired records without traversing client-supplied paths or following symlinks.
     */
    public function cleanup(int $limit = PHP_INT_MAX): int
    {
        if (!is_dir($this->config->directory)) {
            return 0;
        }

        $removed = 0;
        foreach (new Finder()->in($this->config->directory)
            ->recursive(false)
            ->directories() as $entry) {
            $directory = $entry->getPathname();
            if (is_link($directory)) {
                continue;
            }

            $lock = fopen(Path::join($directory, '.lock'), 'c');
            if ($lock === false) {
                throw new RuntimeException('Cannot open upload cleanup lock.');
            }

            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }

                foreach (new Finder()->in($directory)
                    ->recursive(false)
                    ->name('*.json')
                    ->files() as $recordFile) {
                    $path = $recordFile->getPathname();
                    if ($removed >= $limit) {
                        return $removed;
                    }

                    $record = new UploadRecordReader()->read($path);
                    if ($record !== null && $record['expires'] <= new DateTime()->getTimestamp()) {
                        new UploadFilesystem($this->config)->remove(substr($path, 0, -5));
                        $removed++;
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $removed;
    }
}
