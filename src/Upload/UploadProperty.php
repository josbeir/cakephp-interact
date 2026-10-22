<?php
declare(strict_types=1);

namespace Interact\Upload;

use Interact\Attribute\Upload;
use Interact\Island;
use InvalidArgumentException;
use ReflectionProperty;

/**
 * Compiles an explicit upload declaration without allowing objects into JSON state.
 *
 * A property accepts only uploaded files issued by the staging service; application
 * changes may clear or retain that selection but cannot introduce arbitrary objects.
 */
readonly class UploadProperty
{
    public string $name;

    public bool $multiple;

    public ?int $maxFileSize;

    public ?int $maxFiles;

    /**
     * Reject ambiguous, inaccessible, or incompatible PHP declarations during metadata compilation.
     */
    public function __construct(public ReflectionProperty $reflection, Upload $attribute)
    {
        $this->name = $reflection->getName();
        $this->multiple = $attribute->multiple;
        UploadType::validate($reflection, $attribute);
        $this->maxFileSize = $attribute->maxFileSize;
        $this->maxFiles = $attribute->maxFiles;
    }

    /**
     * Resolve the field's byte ceiling without raising application-wide capacity.
     */
    public function fileSize(UploadConfig $config): int
    {
        return min($config->maxFileSize, $this->maxFileSize ?? $config->maxFileSize);
    }

    /**
     * Keep singular fields replaceable and bound collections by both declared ceilings.
     */
    public function fileCount(UploadConfig $config): int
    {
        return $this->multiple ? min($config->maxFiles, $this->maxFiles ?? $config->maxFiles) : 1;
    }

    /**
     * Read a normalized list and reject objects that did not originate from staging.
     *
     * @return list<TemporaryUpload> Unconsumed selected files.
     */
    public function files(Island $island): array
    {
        /** @var mixed $value */
        $value = $this->reflection->getValue($island);
        /** @var mixed $values */
        $values = $value;
        if (!$this->multiple) {
            $values = $value === null ? [] : [$value];
        }

        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException('Multiple uploads require a list.');
        }

        $files = [];
        /** @var mixed $file */
        foreach ($values as $file) {
            if (!$file instanceof TemporaryUpload) {
                throw new InvalidArgumentException('Upload properties accept only staged files.');
            }

            if (!$file->consumed()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Assign a checked selection using its declared singular or collection shape.
     *
     * @param list<TemporaryUpload> $files Available staged files.
     */
    public function assign(Island $island, array $files): void
    {
        $this->reflection->setValue($island, $this->multiple ? $files : $files[0] ?? null);
    }
}
