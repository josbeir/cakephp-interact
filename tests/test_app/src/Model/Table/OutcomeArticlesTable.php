<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Validates articles created by the outcome demo in its own table.
 * Keeping these inserts separate preserves the deterministic listing fixtures.
 */
class OutcomeArticlesTable extends Table
{
    /**
     * Use native entities for this simple creation example.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setEntityClass(Entity::class);
    }

    /**
     * Keep required-title validation in the model rather than the island.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator->notEmptyString('title', 'A title is required.')->maxLength('title', 100);
    }
}
