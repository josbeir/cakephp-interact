<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Marks a typed property as a server-supplied, mount-only parameter.
 *
 * Discovery recognizes this exact marker class, so subclasses would not be discovered.
 * It remains final to make that declaration boundary explicit.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Prop
{
}
