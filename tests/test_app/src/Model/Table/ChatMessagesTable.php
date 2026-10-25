<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Entity;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Stores the demo's shared conversation and bounds the history shown to visitors.
 *
 * Messages are application data, independent of the hub's transient delivery history.
 * The shared demo room is public to demo users; private notifications use separate topics.
 */
class ChatMessagesTable extends Table
{
    /**
     * Use ordinary CakePHP entities for validation and persistence.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setEntityClass(Entity::class);
    }

    /**
     * Select the latest messages in reading order without loading the entire conversation.
     *
     * @param \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity> $query
     *
     * @return \Cake\ORM\Query\SelectQuery<\Cake\ORM\Entity>
     */
    public function findRecent(SelectQuery $query): SelectQuery
    {
        $latest = $this->find()->select(['id'])->orderByDesc('id')->limit(50);

        return $query->where(['ChatMessages.id IN' => $latest])->orderByAsc('ChatMessages.id');
    }

    /**
     * Keep blank messages and oversized drafts out of persistent history.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator->scalar('name')
            ->requirePresence('name', 'create')
            ->notEmptyString('name', 'Choose a display name.')
            ->maxLength('name', 40)
            ->scalar('message')
            ->requirePresence('message', 'create')
            ->notEmptyString('message', 'Write a message first.')
            ->maxLength('message', 500);
    }
}
