<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Composition;

use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Requires a reactive input and declares a standalone URL-owned model for invalid-binding tests.
 * Binding that URL-owned value must fail before constructing an application child.
 */
#[Render(template: 'island/composition_probe')]
class RequiredIsland extends Island
{
    #[Prop(reactive: true)]
    public int $required;

    #[State(writable: true, url: true, modelable: true)]
    public int $value = 0;
}
