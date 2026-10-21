<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * templates/element/Interact/document_upload.php
 *
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\EntityInterface $record
 * @var \Psr\Http\Message\UploadedFileInterface|null $document
 * @var array<string, list<string>> $errors
 */
?>
<?= $this->Form->create($record, [
    'url' => false,
    'type' => 'file',
    'x-interact:submit' => '$server.call("save")',
]) ?>
    <?= $this->Form->control('document', [
        'type' => 'file',
        'label' => 'Document',
        'accept' => '.pdf,.txt',
        'x-interact:model' => 'document',
    ]) ?>
    <?php if ($document !== null): ?>
        <p>Selected: <?=(string)h($document->getClientFilename()) ?></p>
    <?php endif; ?>
    <?php foreach ($errors['_form'] ?? [] as $message): ?>
        <p role="alert"><?=(string)h($message) ?></p>
    <?php endforeach; ?>
    <?= $this->Form->button('Save document') ?>
    <?php if (!$record->isNew()): ?>
        <p role="status">Document saved.</p>
    <?php endif; ?>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
<?= $this->Form->end() ?>
