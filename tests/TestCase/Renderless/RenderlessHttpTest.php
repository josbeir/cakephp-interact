<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Renderless;

use Cake\Core\Configure;
use Cake\Error\Renderer\WebExceptionRenderer;
use Interact\Runtime\Values;
use Interact\Test\TestSuite\Http\HttpTestCase;

/**
 * Saves renderless drafts through real CakePHP middleware, SQLite, and native FormHelper errors.
 */
class RenderlessHttpTest extends HttpTestCase
{
    public function testExplicitSavesPersistAndValidationFeedbackRendersThenClears(): void
    {
        $mount = $this->mounted(url: '/renderless');
        $this->assertResponseContains('PHP save count at last render');
        $this->update([
            'snapshot' => $mount['snapshot'],
            'action' => 'saveDraft',
            'updates' => ['data.title' => 'Renderless draft'],
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('"html":null');
        $first = $this->payload();
        static::assertSame(1, $first['state']['savedCount']);
        /** @var mixed $id */
        $id = $first['state']['articleId'];
        static::assertIsInt($id);
        $table = $this->getTableLocator()->get('FormArticles');
        static::assertSame('Renderless draft', $table->get($id)->get('title'));
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'action' => 'saveDraft',
            'updates' => ['data.title' => ''],
        ]);
        $this->assertResponseCode(422);
        $failed = $this->payload();
        static::assertIsString($failed['html']);
        static::assertStringContainsString('Enter a title.', $failed['html']);
        static::assertSame([], $failed['events']);
        static::assertSame('Renderless draft', $table->get($id)->get('title'));
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'action' => 'saveDraft',
            'updates' => ['data.title' => 'Corrected draft'],
        ]);
        $this->assertResponseOk();
        $cleared = $this->payload();
        static::assertIsString($cleared['html']);
        static::assertStringNotContainsString('Enter a title.', $cleared['html']);
        static::assertSame('Corrected draft', $table->get($id)->get('title'));
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'saveDraft']);
        $this->assertResponseContains('"html":null');
        static::assertSame(3, $this->payload()['state']['savedCount']);
        $this->update(['snapshot' => $this->nextSnapshot()]);
        static::assertIsString($this->payload()['html']);
    }

    public function testConditionalRenderingAndCurrentAuthorization(): void
    {
        $mount = $this->mounted(url: '/renderless');
        $this->update(['snapshot' => $mount['snapshot'], 'action' => 'signal']);
        $this->assertResponseContains('"html":null');
        $this->update(['snapshot' => $this->nextSnapshot(), 'action' => 'signal', 'args' => [5]]);
        static::assertIsString($this->payload()['html']);
        static::assertSame(6, $this->payload()['state']['signals']);
        $snapshot = $this->nextSnapshot();
        Configure::write('Error.exceptionRenderer', WebExceptionRenderer::class);
        $this->session(['demo.role' => 'viewer']);
        $this->update(['snapshot' => $snapshot, 'action' => 'saveDraft']);
        $this->assertResponseCode(403);
    }

    /**
     * Decode the real update envelope for state and database assertions.
     *
     * @return array{html: mixed, state: array<string, mixed>, events: mixed}
     */
    protected function payload(): array
    {
        $payload = Values::map(json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR));

        return ['html' => $payload['html'], 'state' => Values::map($payload['state']), 'events' => $payload['events']];
    }
}
