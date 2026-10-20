<?php
declare(strict_types=1);

namespace TestApp\Form;

use Cake\Form\Form;
use Cake\Form\Schema;
use Cake\Validation\Validation;
use Cake\Validation\Validator;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Validates demonstration documents with CakePHP and saves them only on explicit execution.
 *
 * Permanent files belong to this application, outside the plugin's staging directory.
 * Original names are display metadata and never become destination paths.
 */
class DocumentForm extends Form
{
    /**
     * @var list<string> Display names of files saved by this execution.
     */
    protected array $saved = [];

    /**
     * Supply FormHelper's normal schema without involving a database table.
     */
    protected function buildSchema(Schema $schema): Schema
    {
        return $schema->addField('title', 'string')->addField('document', 'file')->addField('attachments', 'array');
    }

    /**
     * Keep file policy in application validation, including server-detected MIME types.
     */
    public function validationDefault(Validator $validator): Validator
    {
        return $validator->requirePresence('title')
            ->notEmptyString('title', 'Enter a document title.')
            ->maxLength('title', 100)
            ->requirePresence('document')
            ->notEmptyFile('document', 'Choose a document.')
            ->uploadedFile(
                'document',
                ['types' => ['text/plain', 'application/pdf'], 'maxSize' => 1024 * 1024],
                'Choose a PDF or text file up to 1 MiB.',
            )
            ->allowEmptyArray('attachments')
            ->add('attachments', 'documents', [
                'rule' => static fn(mixed $files): bool => (
                    is_array($files)
                    && array_all($files, static fn(mixed $file): bool => Validation::uploadedFile($file, [
                        'types' => ['text/plain', 'application/pdf'],
                        'maxSize' => 1024 * 1024,
                    ]))
                ),
                'message' => 'Attachments must be PDF or text files up to 1 MiB.',
            ]);
    }

    /**
     * Move validated files into private application storage using the native PSR contract.
     *
     * @param array<array-key, mixed> $data Values validated by execute().
     */
    protected function process(array $data): bool
    {
        $directory = TMP . 'demo-documents';
        if (!is_dir($directory) && !mkdir($directory, 0o700, true)) {
            throw new RuntimeException('Cannot create demo document storage.');
        }

        /** @var mixed $document */
        $document = $data['document'];
        /** @var mixed $attachments */
        $attachments = $data['attachments'];
        if (!$document instanceof UploadedFileInterface || !is_array($attachments)) {
            throw new RuntimeException('The document form requires uploaded files.');
        }

        /** @var mixed $file */
        foreach ([$document, ...$attachments] as $file) {
            if (!$file instanceof UploadedFileInterface) {
                throw new RuntimeException('Attachments must be uploaded files.');
            }

            $file->moveTo($directory . '/' . bin2hex(random_bytes(16)) . '.upload');
            $this->saved[] = (string)$file->getClientFilename();
        }

        return true;
    }

    /**
     * Give the island display metadata after successful persistence.
     *
     * @return list<string> Original names, escaped by the application template.
     */
    public function saved(): array
    {
        return $this->saved;
    }
}
