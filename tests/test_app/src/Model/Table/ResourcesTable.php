<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\Database\Expression\FunctionExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\ORM\Entity;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use InvalidArgumentException;

/**
 * Composes resource-library searches, allowed filters, and deterministic result ordering.
 *
 * Matching uses bound SQLite substring expressions so punctuation and SQL wildcard characters
 * remain literal input. Finders leave counting, pagination, and execution to their caller.
 */
class ResourcesTable extends Table
{
    /**
     * Public category choices shared by the fixture library and its search controls.
     */
    public const array CATEGORIES = [
        'cakephp' => 'CakePHP',
        'alpine' => 'Alpine',
        'typescript' => 'TypeScript',
        'forms' => 'Forms',
        'security' => 'Security',
        'testing' => 'Testing',
        'performance' => 'Performance',
        'accessibility' => 'Accessibility',
    ];

    /**
     * Available difficulty filters for the seeded library.
     */
    public const array LEVELS = ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];

    /**
     * Map public sorting choices to model-owned columns and directions.
     */
    protected const array ORDERING = [
        'title' => ['Resources.title' => 'ASC'],
        'title-desc' => ['Resources.title' => 'DESC'],
        'shortest' => ['Resources.minutes' => 'ASC'],
        'longest' => ['Resources.minutes' => 'DESC'],
    ];

    /**
     * Use normal CakePHP entities for query results.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setEntityClass(Entity::class);
    }

    /**
     * Match every whitespace-separated word across titles, summaries, and category names.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity> $query
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity>
     */
    public function findMatching(SelectQuery $query, string $search = ''): SelectQuery
    {
        if (mb_strlen($search) > 200) {
            throw new InvalidArgumentException('Search with up to 200 characters.');
        }

        $terms = preg_split('/\s+/u', mb_strtolower(trim($search)), flags: PREG_SPLIT_NO_EMPTY);
        if ($terms === false) {
            throw new InvalidArgumentException('Enter a valid search term.');
        }

        foreach ($terms as $term) {
            $matches = new QueryExpression([], [], 'OR');
            foreach (['Resources.title', 'Resources.summary', 'Resources.category'] as $field) {
                $lower = new FunctionExpression('LOWER', [$field => 'identifier']);
                $position = new FunctionExpression('INSTR', [$lower, $term]);
                $matches->gt($position, 0, 'integer');
            }

            $query->where($matches);
        }

        return $query;
    }

    /**
     * Apply allowed category and difficulty choices without accepting arbitrary field names.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity> $query
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity>
     */
    public function findFiltered(SelectQuery $query, string $category = '', string $level = ''): SelectQuery
    {
        if ($category !== '' && !isset(self::CATEGORIES[$category])) {
            throw new InvalidArgumentException('Choose an available category.');
        }

        if ($level !== '' && !isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('Choose an available difficulty.');
        }

        if ($category !== '') {
            $query->where(['Resources.category' => $category]);
        }

        if ($level !== '') {
            $query->where(['Resources.level' => $level]);
        }

        return $query;
    }

    /**
     * Compose the library listing with a stable ID tie-breaker across equal titles or durations.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity> $query
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity>
     */
    public function findListing(
        SelectQuery $query,
        string $search = '',
        string $category = '',
        string $level = '',
        string $sort = 'title',
    ): SelectQuery {
        if (!isset(self::ORDERING[$sort])) {
            throw new InvalidArgumentException('Choose an available sort order.');
        }

        return $query->find('matching', search: $search)
            ->find('filtered', category: $category, level: $level)
            ->orderBy(self::ORDERING[$sort])
            ->orderByAsc('Resources.id');
    }
}
