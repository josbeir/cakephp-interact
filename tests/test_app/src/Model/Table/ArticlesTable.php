<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Entity;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Owns article query scopes and validation so demo controllers/islands never assemble domain SQL.
 */
class ArticlesTable extends Table
{
    /**
     * Configure the normal CakePHP entity and schema conventions.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setEntityClass(Entity::class);
    }

    /**
     * Restrict article visibility to the current owner.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity> $query
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity>
     */
    public function findOwnedBy(SelectQuery $query, int $ownerId): SelectQuery
    {
        return $query->where(['Articles.owner_id' => $ownerId]);
    }

    /**
     * Filter and order visible articles while leaving execution and pagination to the caller.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity> $query
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity>
     */
    public function findListing(SelectQuery $query, int $ownerId, string $search = ''): SelectQuery
    {
        return $query->find('ownedBy', ownerId: $ownerId)
            ->where(['Articles.title LIKE' => '%' . $search . '%'])
            ->orderByAsc('Articles.id');
    }

    /**
     * Keep the save action on CakePHP's normal validation path.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator->scalar('title')->notEmptyString('title', 'A title is required.')->maxLength('title', 100);
    }
}
