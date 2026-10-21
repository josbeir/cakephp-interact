<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Declares snapshot state; accepting browser edits is a separate, explicit capability.
 *
 * Discovery recognizes this exact marker class, so subclasses would not be discovered.
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
    ) {
        if ($history && $url === false) {
            throw new InvalidArgumentException('History requires URL-bound state.');
        }
    }
}
