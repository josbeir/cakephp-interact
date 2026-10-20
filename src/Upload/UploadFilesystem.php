<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\Utility\Fs\Path;
use Closure;
use RuntimeException;

/**
 * Owns private record mutations and serializes session changes with filesystem locks.
 */
class UploadFilesystem
{
    /**
     * Share private storage settings with the staging backend.
     */
    public function __construct(protected readonly UploadConfig $config)
    {
    }

    /**
     * Serialize session mutations with a lock outside individual upload lifetimes.
     *
     * @template T
     * @param Closure(string): T $operation Locked filesystem operation.
     * @return T
     */
    public function locked(UploadOwner $owner, Closure $operation): mixed
    {
        $directory = Path::join($this->config->directory, $owner->sessionKey());
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create private upload directory.');
        }

        $lock = fopen(Path::join($directory, '.lock'), 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open upload lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock temporary uploads.');
            }

            return $operation($directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Release the private pair without retaining expired bytes.
     */
    public function remove(string $base): void
    {
        foreach (['.bin', '.json'] as $extension) {
            if (!is_file($base . $extension)) {
                continue;
            }

            unlink($base . $extension);
        }
    }

    /**
     * Atomically publish metadata while the caller holds its session lock.
     *
     * @param array{owner: string, island: string, expires: int, size: int, name: string, type: string} $record Server metadata.
     */
    public function write(string $path, array $record): void
    {
        if (file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot write temporary upload metadata.');
        }

        chmod($path, 0o600);
    }
}
