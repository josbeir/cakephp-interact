<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Demo;

use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use TestApp\Model\Table\ArticlesTable;
use TestApp\Model\Table\ChatMessagesTable;
use TestApp\Model\Table\CitiesTable;
use TestApp\Model\Table\ResourcesTable;

/**
 * Verifies the demo's domain finders against SQLite, including access scopes and dependent
 * membership.
 */
class ModelFinderTest extends TestCase
{
    use LocatorAwareTrait;

    public function testArticleVisibilitySearchAndPaginationRemainComposable(): void
    {
        $articles = $this->fetchTable('Articles');
        $this->assertInstanceOf(ArticlesTable::class, $articles);
        $this->assertSame([99], $articles->find('ownedBy', ownerId: 2)->all()->extract('id')->toList());
        $this->assertSame(
            [2],
            $articles->find('listing', ownerId: 1, search: 'CakePHP')->all()->extract('id')->toList(),
        );
        $this->assertSame(0, $articles->find('listing', ownerId: 1, search: 'missing')->count());
        $this->assertSame(
            [4, 5, 6],
            $articles->find('listing', ownerId: 1)->limit(3)->page(2)->all()->extract('id')->toList(),
        );
        $this->assertSame(
            [3],
            $articles->find('listing', ownerId: 1)->where(['Articles.id' => 3])->all()->extract('id')->toList(),
        );
    }

    public function testChatHistoryIsBoundedChronologicalAndComposable(): void
    {
        $connection = new Connection(['driver' => Sqlite::class, 'database' => ':memory:']);
        $connection->execute((string)file_get_contents(APP . 'config/schema/chat_messages.sql'));

        $messages = new ChatMessagesTable(['connection' => $connection]);
        $this->assertSame([], $messages->find('recent', recipient: 'my-tabs')->all()->toList());
        foreach (range(1, 55) as $number) {
            $messages->saveOrFail($messages->newEntity(['name' => 'Guest', 'message' => 'Message ' . $number]));
        }

        $recent = $messages->find('recent', recipient: 'my-tabs')->all()->extract('message')->toList();
        $this->assertCount(50, $recent);
        $this->assertSame('Message 6', $recent[0]);
        $this->assertSame('Message 55', $recent[49]);
        $this->assertSame(
            ['Message 10'],
            $messages->find('recent', recipient: 'my-tabs')
                ->where(['message' => 'Message 10'])
                ->all()
                ->extract('message')
                ->toList(),
        );
        $this->assertSame(
            0,
            $messages->find('recent', recipient: 'my-tabs')->where(['message' => 'Message 1'])->count(),
        );
        $messages->saveOrFail($messages->newEntity([
            'name' => 'Me',
            'message' => 'My private note',
            'recipient' => 'my-tabs',
        ]));
        foreach (range(1, 60) as $number) {
            $messages->saveOrFail($messages->newEntity([
                'name' => 'Other',
                'message' => 'Hidden ' . $number,
                'recipient' => 'other-tabs',
            ]));
        }

        $visible = $messages->find('recent', recipient: 'my-tabs')->all()->extract('message')->toList();
        $this->assertCount(50, $visible);
        $this->assertSame('My private note', $visible[49]);
        $this->assertSame(
            0,
            $messages->find('visibleTo', recipient: 'other-tabs')->where(['message' => 'My private note'])->count(),
        );
        $this->assertSame(
            0,
            $messages->find('visibleTo', recipient: 'my-tabs')->where(['message LIKE' => 'Hidden%'])->count(),
        );
        $invalid = $messages->newEntity(['name' => str_repeat('n', 41), 'message' => str_repeat('m', 501)]);
        $this->assertArrayHasKey('name', $invalid->getErrors());
        $this->assertArrayHasKey('message', $invalid->getErrors());
    }

    public function testCitySelectionMustBelongToTheSelectedCountry(): void
    {
        $cities = $this->fetchTable('Cities');
        $this->assertInstanceOf(CitiesTable::class, $cities);
        $this->assertSame(
            ['Amsterdam', 'Rotterdam'],
            $cities->find('inCountry', country: 'NL')->all()->extract('name')->toList(),
        );
        $this->assertSame(0, $cities->find('inCountry', country: 'unknown')->count());
        $this->assertSame(1, $cities->find('selection', country: 'BE', cityId: 1)->count());
        $this->assertSame(0, $cities->find('selection', country: 'NL', cityId: 1)->count());
    }

    public function testResourceSearchMatchesWordsAcrossColumnsAndKeepsFiltersComposable(): void
    {
        $resources = $this->fetchTable('Resources');
        $this->assertInstanceOf(ResourcesTable::class, $resources);
        $this->assertSame(1200, $resources->find('listing')->count());
        $this->assertSame(150, $resources->find('listing', search: 'CakePHP')->count());
        $this->assertSame(15, $resources->find('listing', search: '  CAKEPHP   validation  ')->count());
        $this->assertSame(
            5,
            $resources->find('listing', search: 'validation', category: 'cakephp', level: 'beginner')->count(),
        );
        $this->assertSame(80, $resources->find('listing', search: 'practical guide beginner')->count());
        $this->assertSame(
            1,
            $resources->find('matching', search: 'Custom finders')->where(['Resources.id' => 1])->count(),
        );
    }

    public function testResourceSearchTreatsWildcardsAndSqlAsLiteralText(): void
    {
        $resources = $this->fetchTable('Resources');
        $this->assertInstanceOf(ResourcesTable::class, $resources);
        foreach (['%', '_', "' OR 1=1 --", '<script>', 'no such resource'] as $search) {
            $this->assertSame(0, $resources->find('listing', search: $search)->count(), $search);
        }
    }

    public function testResourceOrderingIsStableAcrossPagesAndReadingTimeSorts(): void
    {
        $resources = $this->fetchTable('Resources');
        $this->assertInstanceOf(ResourcesTable::class, $resources);
        $first = $resources->find('listing')->limit(20)->page(1)->all()->extract('id')->toList();
        $second = $resources->find('listing')->limit(20)->page(2)->all()->extract('id')->toList();
        $this->assertCount(20, $first);
        $this->assertCount(20, $second);
        $this->assertSame([], array_intersect($first, $second));
        $this->assertSame($first, $resources->find('listing')->limit(20)->page(1)->all()->extract('id')->toList());
        $this->assertSame(
            [4, 4, 4],
            $resources->find('listing', sort: 'shortest')->limit(3)->all()->extract('minutes')->toList(),
        );
        $this->assertSame(
            [25, 25, 25],
            $resources->find('listing', sort: 'longest')->limit(3)->all()->extract('minutes')->toList(),
        );
    }

    public function testResourceFinderRejectsUnrecognizedFilterAndSortChoices(): void
    {
        $resources = $this->fetchTable('Resources');
        $this->assertInstanceOf(ResourcesTable::class, $resources);
        foreach ([
            ['category' => 'unknown'],
            ['level' => 'unknown'],
            ['sort' => 'Resources.id; DROP TABLE resources'],
            ['search' => str_repeat('x', 201)],
        ] as $arguments) {
            try {
                $resources->find('listing', ...$arguments)->count();
                $this->fail('An unsupported resource search choice must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }
}
