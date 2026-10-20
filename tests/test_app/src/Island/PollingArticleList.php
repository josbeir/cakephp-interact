<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\AsIsland;

/**
 * Exercises background parent renders while retaining independently owned child editor drafts.
 *
 * The application's existing list finders and child keys remain authoritative.
 */
#[AsIsland('polling-article-list', element: 'Interact/polling_article_list')]
class PollingArticleList extends ArticleList
{
}
