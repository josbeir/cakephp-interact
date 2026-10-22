<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Marks a typed property as a server-supplied, mount-only parameter.
 *
 * Metadata compilation recognizes this exact marker class, so subclasses would not be recognized.
 * It remains final to make that declaration boundary explicit.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Prop
{
}
