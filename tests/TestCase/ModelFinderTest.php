<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use TestApp\Model\Table\ArticlesTable;
use TestApp\Model\Table\CitiesTable;

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
}
