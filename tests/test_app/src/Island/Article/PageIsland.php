<?php
declare(strict_types=1);

namespace TestApp\Island\Article;

use Interact\Attribute\Render;

/**
 * Reuses the listing behavior while rendering the action's existing page template as its island
 * content.
 */
#[Render(template: 'Articles/page')]
class PageIsland extends ListingIsland {}
