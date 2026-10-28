<?php
declare(strict_types=1);

namespace Commerce\Island;

use Commerce\Island\Article\EditorIsland as Editor;
use Interact\Attribute\Render;

/**
 * Exercises an explicit native rendering target on an island owned by a plugin.
 */
#[Render(template: 'Acme/Catalog.island/product_card')]
class CrossPluginIsland extends Editor {}
