<?php
declare(strict_types=1);

namespace Commerce\Island;

use Commerce\Island\Article\EditorIsland;
use Interact\Attribute\Render;

/**
 * Changes the root element while keeping the conventional class-derived template.
 */
#[Render(tag: 'section')]
class TaggedIsland extends EditorIsland {}
