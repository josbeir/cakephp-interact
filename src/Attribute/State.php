<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Declares snapshot state; accepting browser edits is a separate, explicit capability.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class State
{
    /**
     * Opt into accepting validated client updates for this property.
     */
    public function __construct(public bool $writable = false)
    {
    }
}
