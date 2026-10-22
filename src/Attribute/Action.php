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
     * Choose whether successful invocations normally render their island template.
     */
    public function __construct(public bool $render = true)
    {
    }
}
