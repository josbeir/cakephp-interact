<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Declares a request-local uploaded-file property backed by private temporary storage.
 *
 * Objects never enter snapshots. Discovery recognizes this exact marker class, so
 * the attribute remains final rather than allowing undiscovered subclasses.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Upload
{
    /**
     * Accept one uploaded file or an ordered collection selected over several transfers.
     */
    public function __construct(public bool $multiple = false)
    {
    }
}
