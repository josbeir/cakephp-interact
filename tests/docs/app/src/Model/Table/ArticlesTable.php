<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Validates article titles before creating records.
 *
 * Save as src/Model/Table/ArticlesTable.php.
 */
class ArticlesTable extends Table
{
    /**
     * Use CakePHP's generic entity for this example's single editable column.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setEntityClass(Entity::class);
    }

    /**
     * Require a title that fits the database column.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator->requirePresence('title', 'create')
            ->notEmptyString('title', 'Enter a title.')
            ->maxLength('title', 120, 'Use up to 120 characters.');
    }
}
