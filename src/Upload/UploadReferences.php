<?php
declare(strict_types=1);

namespace Interact\Upload;

use Interact\Runtime\Values;
use InvalidArgumentException;

/**
 * Checks signed reference lists before a storage backend restores any file objects.
 */
class UploadReferences
{
    /**
     * Accept only bounded lists of server-issued identifiers.
     *
     * @return array<string, list<string>> Checked selections.
     */
    public static function read(mixed $value): array
    {
        $uploads = [];
        /** @var mixed $ids */
        foreach (Values::map($value) as $field => $ids) {
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 256) {
                throw new InvalidArgumentException('Invalid signed upload selection.');
            }

            $checked = [];
            /** @var mixed $id */
            foreach ($ids as $id) {
                if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || in_array($id, $checked, true)) {
                    throw new InvalidArgumentException('Invalid signed upload reference.');
                }

                $checked[] = $id;
            }

            $uploads[$field] = $checked;
        }

        return $uploads;
    }
}
