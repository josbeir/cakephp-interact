<?php
declare(strict_types=1);

namespace App\Island;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\Upload;
use Interact\Island;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Creates a document record from one staged file using normal Table validation.
 *
 * The entity stays on the server so FormHelper can render its validation errors.
 *
 * Save as src/Island/DocumentUpload.php.
 */
#[AsIsland('document-upload', element: 'Interact/document_upload')]
class DocumentUpload extends Island
{
    use LocatorAwareTrait;

    #[Upload(maxFileSize: '1MB')]
    public ?UploadedFileInterface $document = null;

    protected ?EntityInterface $record = null;

    /**
     * Validate and save the record, then move the file into private storage.
     */
    #[Action]
    public function save(): void
    {
        $documents = $this->fetchTable('Documents');
        $this->record = $documents->newEntity([
            'filename' => $this->document?->getClientFilename(),
            'document' => $this->document,
        ]);

        if (!$documents->save($this->record)) {
            $this->setErrors($this->record->getErrors() ?: ['_form' => ['Unable to save the document.']]);

            return;
        }

        $this->document?->moveTo(TMP . 'example-documents/' . (string)$this->record->get('id') . '.upload');
        $this->document = null;
    }

    /**
     * Give FormHelper the current entity, including validation or staging errors.
     *
     * @return array{record: EntityInterface} Request-local form context.
     */
    public function viewData(): array
    {
        $record = $this->record ?? $this->fetchTable('Documents')->newEmptyEntity();
        $record->setErrors($record->getErrors() + $this->errors());

        return ['record' => $record];
    }
}
