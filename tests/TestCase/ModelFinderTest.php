<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

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
        static::assertInstanceOf(ArticlesTable::class, $articles);
        static::assertSame([99], $articles->find('ownedBy', ownerId: 2)->all()->extract('id')->toList());
        static::assertSame(
            [2],
            $articles->find('listing', ownerId: 1, search: 'CakePHP')->all()->extract('id')->toList(),
        );
        static::assertSame(0, $articles->find('listing', ownerId: 1, search: 'missing')->count());
        static::assertSame(
            [4, 5, 6],
            $articles->find('listing', ownerId: 1)->limit(3)->page(2)->all()->extract('id')->toList(),
        );
        static::assertSame(
            [3],
            $articles->find('listing', ownerId: 1)->where(['Articles.id' => 3])->all()->extract('id')->toList(),
        );
    }

    public function testChatHistoryIsBoundedChronologicalAndComposable(): void
    {
        $connection = new Connection(['driver' => Sqlite::class, 'database' => ':memory:']);
        $connection->execute((string)file_get_contents(APP . 'config/schema/chat_messages.sql'));

        $messages = new ChatMessagesTable(['connection' => $connection]);
        static::assertSame([], $messages->find('recent')->all()->toList());
        foreach (range(1, 55) as $number) {
            $messages->saveOrFail($messages->newEntity(['name' => 'Guest', 'message' => 'Message ' . $number]));
        }

        $recent = $messages->find('recent')->all()->extract('message')->toList();
        static::assertCount(50, $recent);
        static::assertSame('Message 6', $recent[0]);
        static::assertSame('Message 55', $recent[49]);
        static::assertSame(
            ['Message 10'],
            $messages->find('recent')->where(['message' => 'Message 10'])->all()->extract('message')->toList(),
        );
        static::assertSame(0, $messages->find('recent')->where(['message' => 'Message 1'])->count());
        $invalid = $messages->newEntity(['name' => str_repeat('n', 41), 'message' => str_repeat('m', 501)]);
        static::assertArrayHasKey('name', $invalid->getErrors());
        static::assertArrayHasKey('message', $invalid->getErrors());
    }

    public function testCitySelectionMustBelongToTheSelectedCountry(): void
    {
        $cities = $this->fetchTable('Cities');
        static::assertInstanceOf(CitiesTable::class, $cities);
        static::assertSame(
            ['Amsterdam', 'Rotterdam'],
            $cities->find('inCountry', country: 'NL')->all()->extract('name')->toList(),
        );
        static::assertSame(0, $cities->find('inCountry', country: 'unknown')->count());
        static::assertSame(1, $cities->find('selection', country: 'BE', cityId: 1)->count());
        static::assertSame(0, $cities->find('selection', country: 'NL', cityId: 1)->count());
    }

    public function testResourceSearchMatchesWordsAcrossColumnsAndKeepsFiltersComposable(): void
    {
        $resources = $this->fetchTable('Resources');
        static::assertInstanceOf(ResourcesTable::class, $resources);
        static::assertSame(1200, $resources->find('listing')->count());
        static::assertSame(150, $resources->find('listing', search: 'CakePHP')->count());
        static::assertSame(15, $resources->find('listing', search: '  CAKEPHP   validation  ')->count());
        static::assertSame(
            5,
            $resources->find('listing', search: 'validation', category: 'cakephp', level: 'beginner')->count(),
        );
        static::assertSame(80, $resources->find('listing', search: 'practical guide beginner')->count());
        static::assertSame(
            1,
            $resources->find('matching', search: 'Custom finders')->where(['Resources.id' => 1])->count(),
        );
    }

    public function testResourceSearchTreatsWildcardsAndSqlAsLiteralText(): void
    {
        $resources = $this->fetchTable('Resources');
        static::assertInstanceOf(ResourcesTable::class, $resources);
        foreach (['%', '_', "' OR 1=1 --", '<script>', 'no such resource'] as $search) {
            static::assertSame(0, $resources->find('listing', search: $search)->count(), $search);
        }
    }

    public function testResourceOrderingIsStableAcrossPagesAndReadingTimeSorts(): void
    {
        $resources = $this->fetchTable('Resources');
        static::assertInstanceOf(ResourcesTable::class, $resources);
        $first = $resources->find('listing')->limit(20)->page(1)->all()->extract('id')->toList();
        $second = $resources->find('listing')->limit(20)->page(2)->all()->extract('id')->toList();
        static::assertCount(20, $first);
        static::assertCount(20, $second);
        static::assertSame([], array_intersect($first, $second));
        static::assertSame($first, $resources->find('listing')->limit(20)->page(1)->all()->extract('id')->toList());
        static::assertSame(
            [4, 4, 4],
            $resources->find('listing', sort: 'shortest')->limit(3)->all()->extract('minutes')->toList(),
        );
        static::assertSame(
            [25, 25, 25],
            $resources->find('listing', sort: 'longest')->limit(3)->all()->extract('minutes')->toList(),
        );
    }

    public function testResourceFinderRejectsUnrecognizedFilterAndSortChoices(): void
    {
        $resources = $this->fetchTable('Resources');
        static::assertInstanceOf(ResourcesTable::class, $resources);
        foreach ([
            ['category' => 'unknown'],
            ['level' => 'unknown'],
            ['sort' => 'Resources.id; DROP TABLE resources'],
            ['search' => str_repeat('x', 201)],
        ] as $arguments) {
            try {
                $resources->find('listing', ...$arguments)->count();
                static::fail('An unsupported resource search choice must be rejected.');
            } catch (InvalidArgumentException $exception) {
                static::assertNotSame('', $exception->getMessage());
            }
        }
    }
}
