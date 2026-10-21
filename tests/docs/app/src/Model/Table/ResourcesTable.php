<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Database\Expression\FunctionExpression;
use Cake\ORM\Entity;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use InvalidArgumentException;

/**
 * Builds a literal, case-insensitive title search with deterministic ordering.
 *
 * This example uses SQLite's INSTR function. Save as src/Model/Table/ResourcesTable.php.
 */
class ResourcesTable extends Table
{
    /**
     * Use generic entities for the read-only result rows.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setEntityClass(Entity::class);
    }

    /**
     * Return a composable query with bound search input and stable ordering.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity> $query
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity>
     */
    public function findMatching(SelectQuery $query, string $search = ''): SelectQuery
    {
        if (mb_strlen($search) > 100) {
            throw new InvalidArgumentException('Search with up to 100 characters.');
        }

        $search = mb_strtolower(trim($search));
        if ($search !== '') {
            $lower = new FunctionExpression('LOWER', ['Resources.title' => 'identifier']);
            $position = new FunctionExpression('INSTR', [$lower, $search]);
            $query->where(static fn($expression) => $expression->gt($position, 0, 'integer'));
        }

        return $query->orderByAsc('Resources.title')->orderByAsc('Resources.id');
    }
}
