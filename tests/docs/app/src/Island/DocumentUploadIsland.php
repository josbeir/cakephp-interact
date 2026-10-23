<?php
declare(strict_types=1);

namespace App\Island;

use App\Model\Table\DocumentsTable;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\Upload;
use Interact\Island;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Creates a document record from one staged file using normal Table validation.
 *
 * The entity stays on the server so FormHelper can render its validation errors.
 *
 * Save as src/Island/DocumentUploadIsland.php.
 */
class DocumentUploadIsland extends Island
{
    use LocatorAwareTrait;

    protected DocumentsTable $documents;

    #[Upload(maxFileSize: '1MB')]
    public ?UploadedFileInterface $document = null;

    protected ?EntityInterface $record = null;

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->documents = $this->fetchTable(DocumentsTable::class);
    }

    /**
     * Validate and save the record, then move the file into private storage.
     */
    #[Action]
    public function save(): void
    {
        $documents = $this->documents;
        $this->record = $documents->newEntity([
            'filename' => $this->document?->getClientFilename(),
            'document' => $this->document,
        ]);

        if (!$documents->save($this->record)) {
            $this->setErrors($this->record->getErrors() ?: ['_form' => ['Unable to save the document.']]);

            return;
        }

        $directory = (string)Configure::read('Documents.directory', TMP . 'example-documents');
        $this->document?->moveTo($directory . '/' . (string)$this->record->get('id') . '.upload');
        $this->document = null;
    }

    /**
     * Give FormHelper the current entity, including validation or staging errors.
     *
     * @return array{record: EntityInterface} Request-local form context.
     */
    public function viewData(): array
    {
        $record = $this->record ?? $this->documents->newEmptyEntity();
        $record->setErrors($record->getErrors() + $this->errors());

        return ['record' => $record];
    }
}
