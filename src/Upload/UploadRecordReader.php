<?php
declare(strict_types=1);

namespace Interact\Upload;

use RuntimeException;

/**
 * Validates private metadata before ownership, quota, or expiry checks use it.
 */
class UploadRecordReader
{
    /**
     * Reject incomplete or damaged records rather than using unchecked filesystem data.
     *
     * @return array{owner: string, island: string, expires: int, size: int, name: string, type: string}|null Validated metadata.
     */
    public function read(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        /** @var mixed $record */
        $record = json_decode((string)file_get_contents($path), true);
        if (
            !is_array($record)
            || !is_string($record['owner'] ?? null)
            || !is_string($record['island'] ?? null)
            || !is_int($record['expires'] ?? null)
            || !is_int($record['size'] ?? null)
            || !is_string($record['name'] ?? null)
            || !is_string($record['type'] ?? null)
        ) {
            throw new RuntimeException('Invalid temporary upload metadata.');
        }

        return [
            'owner' => $record['owner'],
            'island' => $record['island'],
            'expires' => $record['expires'],
            'size' => $record['size'],
            'name' => $record['name'],
            'type' => $record['type'],
        ];
    }
}
