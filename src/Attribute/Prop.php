<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Marks a typed property as a server-supplied, mount-only parameter.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Prop
{
}
