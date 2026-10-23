<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Composition;

use Interact\Composition\ModelBindings;
use Interact\Composition\Relation;
use Interact\Discovery\Definition;
use Interact\Runtime\RenderFrame;

/**
 * Counts real bound-value reads to guard against repeated validation during preparation.
 */
class CountingBindings extends ModelBindings
{
    public int $resolutions = 0;

    /**
     * Observe the extension boundary while retaining actual capability and value validation.
     *
     * @return array<string, mixed> Validated parent values keyed by child property.
     */
    public function values(Relation $relation, Definition $child, RenderFrame $parent): array
    {
        $this->resolutions++;

        return parent::values($relation, $child, $parent);
    }
}
