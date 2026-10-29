<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Documentation;

use Cake\Core\Configure;
use Cake\Utility\Fs\Finder;
use Cake\Utility\Fs\Path;
use Interact\Pagination\PaginationState;
use Interact\Test\Fixture\Documentation\DocumentationControls;
use Interact\View\TemplateView;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Renders documentation snippets through FormHelper and compares their public bindings.
 *
 * This catches helper options that silently produce different controls from the HTML tab.
 */
class DocumentationMarkupTest extends TestCase
{
    public function testHtmlAndFormHelperTabsProduceEquivalentBoundControls(): void
    {
        $directory = TMP . 'documentation-markup-' . bin2hex(random_bytes(8)) . '/';
        mkdir($directory . 'element', 0o700, true);
        /** @var mixed $previous */
        $previous = Configure::read('App.paths.templates');
        Configure::write('App.paths.templates', [$directory]);
        $controls = new DocumentationControls();
        $count = 0;
        try {
            foreach (['directives', 'guide', 'reference'] as $section) {
                $sources = array_map(
                    static fn(SplFileInfo $source): string => $source->getPathname(),
                    iterator_to_array(
                        new Finder()->in(Path::join(dirname(__DIR__, 3), 'docs', $section))
                            ->recursive(false)
                            ->name('*.md')
                            ->all(),
                        false,
                    ),
                );
                sort($sources, SORT_STRING);
                foreach ($sources as $source) {
                    $markdown = (string)file_get_contents($source);
                    $groups = [];
                    preg_match_all(
                        '/::: code-group\s*```html \[HTML\]\n(.*?)\n```\s*```php \[FormHelper\]\n(.*?)\n```\s*:::/s',
                        $markdown,
                        $groups,
                        PREG_SET_ORDER,
                    );
                    foreach ($groups as $group) {
                        $view = new TemplateView();
                        $view->set([
                            'paging' => new PaginationState(),
                            'search' => '',
                            'title' => '',
                            'bio' => '',
                            'country' => '',
                            'category' => '',
                            'level' => '',
                            'format' => 'remote',
                            'business' => false,
                            'extras' => [],
                            'attendees' => null,
                            'form' => ['address' => ['city' => '']],
                        ]);
                        file_put_contents($directory . 'element/example.php', $group[2]);
                        $this->assertSame(
                            $controls->collect($group[1]),
                            $controls->collect($view->element('example')),
                            $source . ': paired examples must expose the same bindings and choices.',
                        );
                        $count++;
                    }
                }
            }

            $this->assertGreaterThanOrEqual(10, $count, 'The documented native control variations must be checked.');
        } finally {
            Configure::write('App.paths.templates', $previous);
            if (is_file($directory . 'element/example.php')) {
                unlink($directory . 'element/example.php');
            }

            rmdir($directory . 'element');
            rmdir($directory);
        }
    }
}
