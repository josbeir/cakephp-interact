<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\ORM\Locator\LocatorInterface;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\TableRegistry;
use Dom\HTMLDocument;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\View\TemplateView;
use PHPUnit\Framework\TestCase;
use TestApp\Model\Table\CommentsTable;
use TestApp\Model\Table\FormArticlesTable;

/**
 * Verifies the demo's native form drafts, association boundaries, validation, and explicit saves.
 * Every render uses the same island, models, schema, and template as the HTTP and browser suites.
 */
class ArticleFormTest extends TestCase
{
    protected LocatorInterface $previousLocator;

    protected Connection $connection;

    protected Runtime $runtime;

    protected IslandContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousLocator = TableRegistry::getTableLocator();
        $this->connection = new Connection(['driver' => Sqlite::class, 'database' => ':memory:']);
        $schema = (string)file_get_contents(APP . 'config/schema/form_articles.sql');
        foreach (explode(';', $schema) as $statement) {
            if (trim($statement) === '') {
                continue;
            }

            $this->connection->execute($statement);
        }

        $locator = new TableLocator();
        $locator->get(FormArticlesTable::class, ['connection' => $this->connection]);
        $locator->get('Comments', ['className' => CommentsTable::class, 'connection' => $this->connection]);
        TableRegistry::setTableLocator($locator);
        $request = new ServerRequest([
            'url' => '/forms',
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);
        $this->context = new IslandContext($request, 'default', '/_interact/update');
        $this->runtime = new Factory(new Registry(), new SnapshotCodec(str_repeat('f', 32)))->create(
            new TemplateView($request),
            $this->context,
        );
    }

    protected function tearDown(): void
    {
        TableRegistry::setTableLocator($this->previousLocator);
        parent::tearDown();
    }

    public function testArticleValidationPreservesEntityErrorsAndSuccessfulSaveDispatches(): void
    {
        $mount = $this->runtime->mount('Article/Form', [], 'form', $this->context);
        $invalid = $this->runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => [
                'data' => [
                    'title' => '',
                    'body' => 'Draft body',
                    'published' => false,
                    'topics' => [],
                    'comments' => [],
                ],
            ],
        ], $this->context);
        static::assertSame(['title' => ['Enter a title.']], $invalid->errors);
        static::assertStringContainsString('Enter a title.', $invalid->html);
        static::assertSame([], $invalid->events);
        $valid = $this->runtime->update([
            'snapshot' => $invalid->snapshot,
            'action' => 'save',
            'updates' => ['data.title' => 'CakePHP examples'],
        ], $this->context);
        static::assertSame([], $valid->errors);
        static::assertTrue($valid->state['saved']);
        static::assertSame('article-saved', $valid->events[0]['name']);
        static::assertSame(1, TableRegistry::getTableLocator()->get(FormArticlesTable::class)->find()->count());
    }

    public function testNestedArticleDraftMarshallingValidationAndExplicitSaves(): void
    {
        $runtime = $this->runtime;
        $context = $this->context;
        $mount = $runtime->mount('Article/Form', [], 'form', $context);
        $draft = [
            'title' => 'Nested article',
            'body' => '<script>draft</script>',
            'published' => true,
            'topics' => ['cakephp', 'php'],
            'comments' => [['body' => '', 'approved' => false]],
        ];
        $preview = $runtime->update(['snapshot' => $mount->snapshot, 'updates' => ['data' => $draft]], $context);
        static::assertSame($draft, $preview->state['data']);
        static::assertSame(
            0,
            $this->connection->execute('SELECT COUNT(*) FROM form_articles')->fetchColumn(0),
        );
        $invalid = $runtime->update(['snapshot' => $preview->snapshot, 'action' => 'save'], $context);
        static::assertSame(['comments.0.body' => ['Enter a comment.']], $invalid->errors);
        static::assertSame($draft, $invalid->state['data']);
        static::assertStringContainsString('Enter a comment.', $invalid->html);
        static::assertStringContainsString('&lt;script&gt;draft&lt;/script&gt;', $invalid->html);
        static::assertSame(0, $this->connection->execute('SELECT COUNT(*) FROM comments')->fetchColumn(0));
        $added = $runtime->update([
            'snapshot' => $invalid->snapshot,
            'action' => 'addComment',
            'updates' => ['data.comments.0.body' => 'First comment'],
        ], $context);
        static::assertIsArray($added->state['data']);
        static::assertIsArray($added->state['data']['comments']);
        static::assertCount(2, $added->state['data']['comments']);
        $removed = $runtime->update([
            'snapshot' => $added->snapshot,
            'action' => 'removeComment',
            'args' => [1],
        ], $context);
        static::assertIsArray($removed->state['data']);
        static::assertIsArray($removed->state['data']['comments']);
        static::assertCount(1, $removed->state['data']['comments']);
        $saved = $runtime->update([
            'snapshot' => $removed->snapshot,
            'action' => 'save',
            'updates' => ['data.comments.0.approved' => true],
        ], $context);
        static::assertSame([], $saved->errors);
        static::assertTrue($saved->state['saved']);
        $articles = TableRegistry::getTableLocator()->get(FormArticlesTable::class);
        $article = $articles->get($saved->events[0]['detail']['id']);
        static::assertSame('<script>draft</script>', $article->get('body'));
        static::assertTrue($article->get('published'));
        static::assertSame(['cakephp', 'php'], $article->get('topics'));
        $comment = TableRegistry::getTableLocator()->get('Comments')->find()->firstOrFail();
        static::assertSame('First comment', $comment->get('body'));
        static::assertTrue($comment->get('approved'));
        static::assertSame($article->get('id'), $comment->get('article_id'));
        $preview = $runtime->update([
            'snapshot' => $saved->snapshot,
            'updates' => ['data.title' => 'Unsaved title'],
        ], $context);
        static::assertFalse($preview->state['saved']);
        static::assertSame('Nested article', $articles->get($article->get('id'))->get('title'));
    }

    public function testFormHelperReadsDraftAndFailedEntityValuesWithoutFieldOverrides(): void
    {
        $runtime = $this->runtime;
        $context = $this->context;
        $mount = $runtime->mount('Article/Form', [], 'form', $context);
        $draft = [
            'title' => 'Current title',
            'body' => 'Body <with> markup',
            'published' => true,
            'topics' => ['php'],
            'comments' => [['body' => 'Current comment', 'approved' => true]],
        ];
        $preview = $runtime->update(['snapshot' => $mount->snapshot, 'updates' => ['data' => $draft]], $context);
        $html = HTMLDocument::createFromString('<!doctype html><html><body>' . $preview->html . '</body></html>');
        static::assertSame('Current title', $html->querySelector('input[name="title"]')?->getAttribute('value'));
        static::assertSame('Body <with> markup', $html->querySelector('textarea[name="body"]')?->textContent);
        static::assertTrue($html->querySelector('input[type="checkbox"][name="published"]')?->hasAttribute('checked'));
        static::assertTrue($html->querySelector('input[name="topics[]"][value="php"]')?->hasAttribute('checked'));
        static::assertFalse($html->querySelector('input[name="topics[]"][value="cakephp"]')?->hasAttribute('checked'));
        static::assertSame(
            'Current comment',
            $html->querySelector('input[name="comments[0][body]"]')?->getAttribute('value'),
        );
        static::assertTrue(
            $html->querySelector('input[type="checkbox"][name="comments[0][approved]"]')?->hasAttribute('checked'),
        );
        $invalidTitle = str_repeat('x', 121);
        $failed = $runtime->update([
            'snapshot' => $preview->snapshot,
            'action' => 'save',
            'updates' => ['data.title' => $invalidTitle],
        ], $context);
        static::assertSame(['title' => ['Use up to 120 characters.']], $failed->errors);
        $html = HTMLDocument::createFromString('<!doctype html><html><body>' . $failed->html . '</body></html>');
        static::assertSame($invalidTitle, $html->querySelector('input[name="title"]')?->getAttribute('value'));
        static::assertSame('Body <with> markup', $html->querySelector('textarea[name="body"]')?->textContent);
        static::assertSame(
            'Current comment',
            $html->querySelector('input[name="comments[0][body]"]')?->getAttribute('value'),
        );
    }

    public function testCreateFormRejectsAssociationIdsBeforeMarshallingAndIgnoresUnlistedFields(): void
    {
        $runtime = $this->runtime;
        $context = $this->context;
        $mount = $runtime->mount('Article/Form', [], 'form', $context);
        $forged = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => ['data.comments.0.id' => 999],
        ], $context);
        static::assertSame(['_form' => ['Existing comment IDs are not accepted.']], $forged->errors);
        $forged = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => ['data.id' => null],
        ], $context);
        static::assertSame(['_form' => ['Existing article IDs are not accepted.']], $forged->errors);
        static::assertSame(
            0,
            $this->connection->execute('SELECT COUNT(*) FROM form_articles')->fetchColumn(0),
        );
        $valid = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => ['data.title' => 'Allowed fields', 'data.comments' => [], 'data.owner_id' => 999],
        ], $context);
        static::assertSame([], $valid->errors);
        static::assertSame(
            1,
            $this->connection->execute('SELECT COUNT(*) FROM form_articles')->fetchColumn(0),
        );
    }
}
