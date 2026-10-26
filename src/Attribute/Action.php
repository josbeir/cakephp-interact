<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Explicitly exposes one public application method to island requests.
 *
 * Metadata compilation recognizes this exact marker class, so subclasses would not be recognized.
 * It remains final to make that declaration boundary explicit.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Action
{
    /**
     * Choose rendering and streaming policy, and optionally listen for a literal or prop-scoped event.
     */
    public function __construct(public bool $render = true, public bool $stream = false, public ?string $on = null)
    {
    }
}
