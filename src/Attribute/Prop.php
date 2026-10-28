<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Marks a typed property as a server-supplied parameter, optionally updated by its parent.
 *
 * Metadata compilation recognizes this exact marker class, so subclasses would not be recognized.
 * It remains final to make that declaration boundary explicit.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Prop
{
    /**
     * Allow verified parent renders to replace this input without remounting the child.
     */
    public function __construct(public bool $reactive = false) {}
}
