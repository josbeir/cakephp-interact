<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Validates article drafts and configures native associated comment marshalling.
 *
 * Save as src/Model/Table/FormArticlesTable.php.
 */
class FormArticlesTable extends Table
{
    /**
     * Configure generic entities, JSON topics, and the explicitly saved association.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setEntityClass(Entity::class);
        $this->getSchema()->setColumnType('topics', 'json');
        $this->hasMany('Comments', ['className' => CommentsTable::class])->setForeignKey('article_id');
    }

    /**
     * Require a title that fits the database column.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator->requirePresence('title', 'create')
            ->notEmptyString('title', 'Enter a title.')
            ->maxLength('title', 120, 'Use up to 120 characters.')
            ->scalar('body')
            ->allowEmptyString('body')
            ->boolean('published')
            ->allowEmptyArray('topics')
            ->multipleOptions('topics', ['in' => ['cakephp', 'php']]);
    }
}
