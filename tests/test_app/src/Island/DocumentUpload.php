<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Attribute\Upload;
use Interact\Island;
use Psr\Http\Message\UploadedFileInterface;
use TestApp\Form\DocumentForm;

/**
 * Demonstrates staged files alongside an ordinary draft and an explicit CakePHP Form submission.
 *
 * This public example has no protected records. Files remain temporary until save(),
 * and application validation errors retain the same selection for correction.
 */
#[AsIsland('document-upload', element: 'Interact/document_upload')]
class DocumentUpload extends Island
{
    #[State(writable: true)]
    public string $title = '';

    #[Upload]
    public ?UploadedFileInterface $document = null;

    /**
     * @var list<UploadedFileInterface> Attachments added over one or more chooser selections.
     */
    #[Upload(multiple: true)]
    public array $attachments = [];

    /**
     * @var list<string> Application-owned confirmation metadata, without storage paths.
     */
    #[State]
    public array $savedFiles = [];

    protected ?DocumentForm $form = null;

    /**
     * Validate the draft and selected files, then let the application Form persist them.
     */
    #[Action]
    public function save(): void
    {
        $this->form = new DocumentForm();
        if (!$this->form->execute([
            'title' => $this->title,
            'document' => $this->document,
            'attachments' => $this->attachments,
        ])) {
            $this->setErrors($this->form->getErrors());

            return;
        }

        $this->savedFiles = $this->form->saved();
    }

    /**
     * Keep failed Form validation available to FormHelper without transporting the Form object.
     *
     * @return array{form: DocumentForm} Request-local application form context.
     */
    public function viewData(): array
    {
        return ['form' => $this->form ?? new DocumentForm()];
    }
}
