<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\AsIsland;

/**
 * Exercises polling on a real entity form without duplicating its persistence or authorization.
 *
 * The dedicated acceptance page keeps these timers separate from the ordinary editing demo.
 */
#[AsIsland('polling-article-editor', element: 'Interact/polling_article_editor')]
class PollingArticleEditor extends ArticleEditor
{
}
