<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\I18n\DateTime;
use DirectoryIterator;

/**
 * Prunes expired records and abandoned staging fragments within one locked session directory.
 * Callers hold the session lock so active copies and metadata publication cannot be removed.
 * Lock files remain in place to preserve synchronization between existing and future holders.
 */
class UploadPruner
{
    /**
     * Share retention settings with staging and the scheduled global sweep.
     */
    public function __construct(protected readonly UploadConfig $config)
    {
    }

    /**
     * Bound inspected entries independently of removals while the caller holds the session lock.
     *
     * @param string $directory Server-owned session directory, never a browser-supplied path.
     * @param int $limit Maximum removed records or abandoned fragments.
     * @param int $scanLimit Maximum inspected directory entries, including skipped entries.
     * @return int Removed records or abandoned fragments; a record pair counts once.
     */
    public function prune(string $directory, int $limit = PHP_INT_MAX, int $scanLimit = PHP_INT_MAX): int
    {
        $removed = 0;
        $scanned = 0;
        $now = new DateTime()->getTimestamp();
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($scanned++ >= $scanLimit || $removed >= $limit) {
                break;
            }

            if (!$entry->isLink() && $entry->isFile()) {
                $removed += (int)$this->removeExpired($entry->getPathname(), $now);
            }
        }

        return $removed;
    }

    /**
     * Reclaim expired metadata pairs and old unpublished bytes without following symlinks.
     */
    protected function removeExpired(string $path, int $now): bool
    {
        $name = basename($path);
        if (preg_match('/^[a-f0-9]{32}\.json$/D', $name)) {
            $record = new UploadRecordReader()->read($path);
            if ($record !== null && $record['expires'] <= $now) {
                new UploadFilesystem($this->config)->remove(substr($path, 0, -5));

                return true;
            }

            return false;
        }

        if (!preg_match('/^[a-f0-9]{32}\.(?:bin|json\.[a-f0-9]{32}\.tmp)$/D', $name)) {
            return false;
        }

        $modified = filemtime($path);
        if ($modified === false || $modified > $now - $this->config->ttl) {
            return false;
        }

        if (str_ends_with($name, '.bin') && file_exists(substr($path, 0, -4) . '.json')) {
            return false;
        }

        unlink($path);

        return true;
    }
}
