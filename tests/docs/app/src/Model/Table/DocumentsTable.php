<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Validates document records and their transient uploaded-file field before saving.
 *
 * Save as src/Model/Table/DocumentsTable.php.
 */
class DocumentsTable extends Table
{
    /**
     * Use a generic entity for the record and its transient file field.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setEntityClass(Entity::class);
    }

    /**
     * Require a PDF or text document and keep its display name within the column size.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator->maxLength('filename', 255)
            ->requirePresence('document', 'create')
            ->notEmptyFile('document', 'Choose a document.')
            ->uploadedFile(
                'document',
                ['types' => ['application/pdf', 'text/plain'], 'maxSize' => 1024 * 1024],
                'Choose a PDF or text file up to 1 MiB.',
            );
    }
}
