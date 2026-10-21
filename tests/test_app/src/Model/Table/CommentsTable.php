<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Validates new comment drafts through CakePHP's associated marshaller.
 *
 * Save as src/Model/Table/CommentsTable.php. Resource authorization belongs to the
 * application before existing association IDs are accepted.
 */
class CommentsTable extends Table
{
    /**
     * Configure native generic entities for this focused example.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setEntityClass(Entity::class);
    }

    /**
     * Require comment text and accept only boolean approval flags.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator->requirePresence('body', 'create')
            ->notEmptyString('body', 'Enter a comment.')
            ->maxLength('body', 500, 'Use up to 500 characters.')
            ->boolean('approved');
    }
}
