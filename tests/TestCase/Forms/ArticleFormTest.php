<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Forms;

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
        $this->assertSame(['title' => ['Enter a title.']], $invalid->errors);
        $this->assertStringContainsString('Enter a title.', $invalid->html);
        $this->assertSame([], $invalid->events);
        $valid = $this->runtime->update([
            'snapshot' => $invalid->snapshot,
            'action' => 'save',
            'updates' => ['data.title' => 'CakePHP examples'],
        ], $this->context);
        $this->assertSame([], $valid->errors);
        $this->assertTrue($valid->state['saved']);
        $this->assertSame('article-saved', $valid->events[0]['name']);
        $this->assertSame(1, TableRegistry::getTableLocator()->get(FormArticlesTable::class)->find()->count());
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
        $this->assertSame($draft, $preview->state['data']);
        $this->assertSame(
            0,
            $this->connection->execute('SELECT COUNT(*) FROM form_articles')->fetchColumn(0),
        );
        $invalid = $runtime->update(['snapshot' => $preview->snapshot, 'action' => 'save'], $context);
        $this->assertSame(['comments.0.body' => ['Enter a comment.']], $invalid->errors);
        $this->assertSame($draft, $invalid->state['data']);
        $this->assertStringContainsString('Enter a comment.', $invalid->html);
        $this->assertStringContainsString('&lt;script&gt;draft&lt;/script&gt;', $invalid->html);
        $this->assertSame(0, $this->connection->execute('SELECT COUNT(*) FROM comments')->fetchColumn(0));
        $saved = $runtime->update([
            'snapshot' => $invalid->snapshot,
            'action' => 'save',
            'updates' => ['data.comments.0.body' => 'First comment', 'data.comments.0.approved' => true],
        ], $context);
        $this->assertSame([], $saved->errors);
        $this->assertTrue($saved->state['saved']);
        $articles = TableRegistry::getTableLocator()->get(FormArticlesTable::class);
        $article = $articles->get($saved->events[0]['detail']['id']);
        $this->assertSame('<script>draft</script>', $article->get('body'));
        $this->assertTrue($article->get('published'));
        $this->assertSame(['cakephp', 'php'], $article->get('topics'));
        $comment = TableRegistry::getTableLocator()->get('Comments')->find()->firstOrFail();
        $this->assertSame('First comment', $comment->get('body'));
        $this->assertTrue($comment->get('approved'));
        $this->assertSame($article->get('id'), $comment->get('article_id'));
        $preview = $runtime->update([
            'snapshot' => $saved->snapshot,
            'updates' => ['data.title' => 'Unsaved title'],
        ], $context);
        $this->assertFalse($preview->state['saved']);
        $this->assertSame('Nested article', $articles->get($article->get('id'))->get('title'));
    }

    public function testCommentRowsCanBeAddedAndRemovedWithoutSaving(): void
    {
        $runtime = $this->runtime;
        $context = $this->context;
        $mount = $runtime->mount('Article/Form', [], 'form', $context);
        $added = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'addComment',
            'updates' => ['data.comments.0.body' => 'First comment'],
        ], $context);
        $this->assertIsArray($added->state['data']);
        $this->assertIsArray($added->state['data']['comments']);
        $this->assertCount(2, $added->state['data']['comments']);
        $removed = $runtime->update([
            'snapshot' => $added->snapshot,
            'action' => 'removeComment',
            'args' => [1],
        ], $context);
        $this->assertIsArray($removed->state['data']);
        $this->assertIsArray($removed->state['data']['comments']);
        $this->assertCount(1, $removed->state['data']['comments']);
        $this->assertSame([['body' => 'First comment', 'approved' => false]], $removed->state['data']['comments']);
        $this->assertSame(0, $this->connection->execute('SELECT COUNT(*) FROM comments')->fetchColumn(0));
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
        $this->assertSame('Current title', $html->querySelector('input[name="title"]')?->getAttribute('value'));
        $this->assertSame('Body <with> markup', $html->querySelector('textarea[name="body"]')?->textContent);
        $this->assertTrue($html->querySelector('input[type="checkbox"][name="published"]')?->hasAttribute('checked'));
        $this->assertTrue($html->querySelector('input[name="topics[]"][value="php"]')?->hasAttribute('checked'));
        $this->assertFalse($html->querySelector('input[name="topics[]"][value="cakephp"]')?->hasAttribute('checked'));
        $this->assertSame(
            'Current comment',
            $html->querySelector('input[name="comments[0][body]"]')?->getAttribute('value'),
        );
        $this->assertTrue(
            $html->querySelector('input[type="checkbox"][name="comments[0][approved]"]')?->hasAttribute('checked'),
        );
        $invalidTitle = str_repeat('x', 121);
        $failed = $runtime->update([
            'snapshot' => $preview->snapshot,
            'action' => 'save',
            'updates' => ['data.title' => $invalidTitle],
        ], $context);
        $this->assertSame(['title' => ['Use up to 120 characters.']], $failed->errors);
        $html = HTMLDocument::createFromString('<!doctype html><html><body>' . $failed->html . '</body></html>');
        $this->assertSame($invalidTitle, $html->querySelector('input[name="title"]')?->getAttribute('value'));
        $this->assertSame('Body <with> markup', $html->querySelector('textarea[name="body"]')?->textContent);
        $this->assertSame(
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
        $this->assertSame(['_form' => ['Existing comment IDs are not accepted.']], $forged->errors);
        $forged = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => ['data.id' => null],
        ], $context);
        $this->assertSame(['_form' => ['Existing article IDs are not accepted.']], $forged->errors);
        $this->assertSame(
            0,
            $this->connection->execute('SELECT COUNT(*) FROM form_articles')->fetchColumn(0),
        );
        $valid = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'save',
            'updates' => ['data.title' => 'Allowed fields', 'data.comments' => [], 'data.owner_id' => 999],
        ], $context);
        $this->assertSame([], $valid->errors);
        $this->assertSame(
            1,
            $this->connection->execute('SELECT COUNT(*) FROM form_articles')->fetchColumn(0),
        );
    }
}
