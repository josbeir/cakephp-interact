<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Explicitly exposes one public application method to island requests.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Action
{
}
