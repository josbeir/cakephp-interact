<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Explicitly exposes one public application method to island requests.
 *
 * Discovery recognizes this exact marker class, so subclasses would not be discovered.
 * It remains final to make that declaration boundary explicit.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Action
{
}
