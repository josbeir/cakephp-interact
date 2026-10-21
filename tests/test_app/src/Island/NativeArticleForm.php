<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\AsIsland;

/**
 * Exercises plain native names with the same draft actions and persistence as FormHelper.
 * The embedded child retains its own bindings and request ownership.
 */
#[AsIsland('native-article-form', element: 'Interact/native_article_form')]
class NativeArticleForm extends ArticleForm
{
}
