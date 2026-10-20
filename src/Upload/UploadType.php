<?php
declare(strict_types=1);

namespace Interact\Upload;

use Interact\Attribute\Upload;
use Interact\Runtime\Values;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Validates the native PHP declaration required for explicit uploaded-file properties.
 */
class UploadType
{
    /**
     * Reject shapes that cannot receive a checked single file or ordered file list.
     */
    public static function validate(ReflectionProperty $reflection, Upload $declaration): void
    {
        Values::path($reflection->getName());
        $type = $reflection->getType();
        if (
            !$reflection->isPublic()
            || $reflection->isStatic()
            || $reflection->isReadOnly()
            || !$type instanceof ReflectionNamedType
            || !$reflection->hasDefaultValue()
            || (
                $declaration->multiple
                    ? $type->getName() !== 'array'
                    || $reflection->getDefaultValue() !== []
                    : $type->getName() !== UploadedFileInterface::class
                    || !$type->allowsNull()
                    || $reflection->getDefaultValue() !== null
            )
        ) {
            throw new InvalidArgumentException(
                'Uploads require a public nullable UploadedFileInterface or an empty array for multiple files.',
            );
        }
    }
}
