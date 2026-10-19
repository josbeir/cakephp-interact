<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\AsIsland;

/**
 * Reuses the listing behavior while rendering the action's existing page template as its island
 * content.
 */
#[AsIsland('article-page', template: 'Articles/page')]
class ArticlePage extends ArticleList
{
}
