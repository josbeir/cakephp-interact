<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\Utility\Fs\Finder;
use function Cake\I18n\__d;

/**
 * Checks session, island, and field capacity while the caller holds the session lock.
 */
class UploadQuota
{
    /**
     * Share private storage settings with the staging backend.
     */
    public function __construct(protected readonly UploadConfig $config)
    {
    }

    /**
     * Count all session records, including unacknowledged transfers, before committing another file.
     */
    public function check(string $directory, UploadOwner $owner, int $size, ?string $replacingId = null): void
    {
        $sessionSize = $size;
        $islandSize = $size;
        $fieldCount = 0;
        $sessionCount = 1;
        foreach (new Finder()->in($directory)
            ->recursive(false)
            ->name('*.json')
            ->files() as $entry) {
            $path = $entry->getPathname();
            $record = new UploadRecordReader()->read($path);
            if ($record === null) {
                continue;
            }

            $sessionCount++;
            $sessionSize += $record['size'];
            if ($record['island'] === $owner->islandKey()) {
                $islandSize += $record['size'];
            }

            if ($record['owner'] === $owner->key() && basename($path, '.json') !== $replacingId) {
                $fieldCount++;
            }
        }

        if (
            $sessionSize > $this->config->maxSessionSize
            || $islandSize > $this->config->maxIslandSize
            || $fieldCount >= $this->config->maxFiles
            || $sessionCount > $this->config->maxSessionFiles
        ) {
            throw new UploadRejected(__d(
                'interact',
                'The temporary upload capacity has been reached. Remove files and try again.',
            ));
        }
    }
}
