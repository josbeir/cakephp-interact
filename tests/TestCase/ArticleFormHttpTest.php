<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\Test\TestSuite\HttpTestCase;

/**
 * Verifies the demo's form workflow through the real middleware and update endpoint.
 */
class ArticleFormHttpTest extends HttpTestCase
{
    /**
     * Mount and explicitly save an associated article draft.
     */
    public function testFormMountAndAssociatedSave(): void
    {
        $mount = $this->mounted(url: '/forms');
        $this->assertResponseContains('x-interact:model="data"');
        $this->assertResponseContains('Write an article');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'action' => 'save',
            'updates' => [
                'data.title' => 'Demo form',
                'data.comments.0.body' => 'Saved through the real update endpoint.',
                'data.comments.0.approved' => true,
            ],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('"saved":true');
        $this->assertResponseContains('article-saved');
    }
}
