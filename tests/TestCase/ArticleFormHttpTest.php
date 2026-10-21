<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Composer\Autoload\ClassLoader;
use Interact\Test\TestSuite\HttpTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Verifies that the demo owns its form workflow independently of documentation fixtures.
 *
 * A fresh process prevents previously loaded documentation classes from hiding
 * dependencies during the real CakePHP mount and associated save requests.
 */
class ArticleFormHttpTest extends HttpTestCase
{
    /**
     * Mount and save the demo with documentation autoloading disabled.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormMountAndSaveDoNotLoadDocumentationApplication(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->setPsr4('App\\', []);
        }

        $mount = $this->mounted(url: '/forms');
        $this->assertResponseContains('x-interact:model="data"');
        $this->assertResponseContains('Write an article');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'action' => 'save',
            'updates' => [
                'data.title' => 'Standalone demo form',
                'data.comments.0.body' => 'Saved without documentation fixtures.',
                'data.comments.0.approved' => true,
            ],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('"saved":true');
        $this->assertResponseContains('article-saved');
        $documentation = dirname(__DIR__) . '/docs/app/';
        static::assertSame(
            [],
            array_values(array_filter(
                get_included_files(),
                static fn(string $file): bool => str_starts_with($file, $documentation),
            )),
        );
    }
}
