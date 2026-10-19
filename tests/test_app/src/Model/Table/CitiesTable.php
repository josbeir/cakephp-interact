<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;

/**
 * Encapsulates country-dependent city choices and membership checks used by the selectors.
 */
class CitiesTable extends Table
{
    /**
     * Restrict and order the available cities by country.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity> $query
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity>
     */
    public function findInCountry(SelectQuery $query, string $country): SelectQuery
    {
        return $query->where(['Cities.country' => $country])->orderByAsc('Cities.name');
    }

    /**
     * Check a selected city within the same country-scoped query.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity> $query
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity>
     */
    public function findSelection(SelectQuery $query, string $country, int $cityId): SelectQuery
    {
        return $query->find('inCountry', country: $country)->where(['Cities.id' => $cityId]);
    }
}
