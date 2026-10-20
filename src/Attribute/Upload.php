<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;
use Cake\Utility\Text;
use InvalidArgumentException;

/**
 * Declares a request-local uploaded-file property backed by private temporary storage.
 *
 * Objects never enter snapshots. Discovery recognizes this exact marker class, so
 * the attribute remains final rather than allowing undiscovered subclasses.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Upload
{
    public ?int $maxFileSize;

    /**
     * Accept files with optional staging limits bounded by application-wide ceilings.
     *
     * File sizes accept integer bytes or CakePHP size strings. maxFiles applies to
     * multiple-file properties; omitted limits inherit the application's settings.
     *
     * @throws InvalidArgumentException For invalid sizes, counts, or a count on a singular field.
     */
    public function __construct(
        public bool $multiple = false,
        int|string|null $maxFileSize = null,
        public ?int $maxFiles = null,
    ) {
        if (is_string($maxFileSize)) {
            $maxFileSize = Text::parseFileSize(trim($maxFileSize), 0);
        }

        if (($maxFileSize !== null && $maxFileSize < 1) || ($maxFiles !== null && $maxFiles < 1)) {
            throw new InvalidArgumentException('Upload field limits must be positive.');
        }

        if (!$multiple && $maxFiles !== null) {
            throw new InvalidArgumentException('Upload maxFiles requires multiple: true.');
        }

        $this->maxFileSize = $maxFileSize;
    }
}
