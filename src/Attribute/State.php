<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Declares snapshot state; accepting browser edits is a separate, explicit capability.
 *
 * Metadata compilation recognizes this exact marker class, so subclasses would not be recognized.
 * It remains final to make that declaration boundary explicit.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class State
{
    /**
     * Opt into accepting validated client updates for this property.
     */
    public function __construct(
        public bool $writable = false,
        public bool|string $url = false,
        public bool $history = false,
        public bool $modelable = false,
    ) {
        if ($history && $url === false) {
            throw new InvalidArgumentException('History requires URL-bound state.');
        }

        if ($modelable && !$writable) {
            throw new InvalidArgumentException('Modelable state must be writable.');
        }
    }
}
