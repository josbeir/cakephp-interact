<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var \TestApp\Form\DocumentForm $form
 * @var \Interact\Upload\TemporaryUpload|null $document
 * @var list<\Interact\Upload\TemporaryUpload> $attachments
 * @var string $title
 * @var list<string> $savedFiles
 */
?>
<section class="demo-card upload-card" aria-label="Document uploads">
    <div class="card-heading">
        <div>
            <p class="eyebrow">Staging / Validation / Explicit saving</p>
            <h2>A document and its attachments.</h2>
        </div>
        <span class="capability">File uploads</span>
    </div>
    <p class="card-description">
        Choose PDF or text files up to 1 MiB each. Add attachments in several selections.
        Transfers do not save documents; use Save documents when the draft is ready.
    </p>

    <?= $this->Form->create($form, [
        'url' => false,
        'type' => 'file',
        'id' => 'document-upload-form',
        'x-interact:submit' => '$server.call("save")',
    ]) ?>
        <?= $this->Form->control('title', [
            'label' => 'Document title',
            'value' => $title,
            'x-interact:model' => 'title',
        ]) ?>

        <?php foreach (['document' => 'Main document', 'attachments' => 'Attachments'] as $field => $label): ?>
            <div class="upload-field" role="group" aria-label="<?= (string)h($label) ?> upload">
                <?= $this->Form->control($field, [
                    'type' => 'file',
                    'label' => $label,
                    'multiple' => $field === 'attachments',
                    'accept' => '.pdf,.txt',
                    'x-interact:model' => $field,
                ]) ?>
                <progress
                    max="100"
                    aria-label="<?= (string)h($label) ?> upload progress"
                    x-show="$server.uploads.<?= $field ?>.pending"
                    :value="$server.uploads.<?= $field ?>.progress"
                    x-cloak
                ></progress>
                <?php
                $selectedFiles = $attachments;
                if ($field === 'document') {
                    $selectedFiles = $document === null ? [] : [$document];
                }
                ?>
                <ul class="upload-list" aria-label="<?= (string)h($label) ?> files">
                    <?php foreach ($selectedFiles as $file): ?>
                        <li class="upload-item">
                            <span class="upload-filename"><?= (string)h($file->getClientFilename()) ?></span>
                            <span class="quiet">ready</span>
                            <button
                                type="button"
                                @click="$server.uploads.<?= $field ?>.remove('<?= (string)h($file->id) ?>')"
                            >Remove</button>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <ul class="upload-list" aria-label="<?= (string)h($label) ?> transfers">
                    <template x-for="file in $server.uploads.<?= $field ?>.files" :key="file.id">
                        <li class="upload-item" x-show="file.status !== 'ready'">
                            <span class="upload-filename" x-text="file.name"></span>
                            <span class="quiet" x-text="file.status"></span>
                            <div class="button-row">
                                <button
                                    type="button"
                                    x-show="file.status === 'queued' || file.status === 'uploading'"
                                    @click="$server.uploads.<?= $field ?>.cancel(file.id)"
                                >Cancel</button>
                                <button
                                    type="button"
                                    x-show="file.status === 'failed'"
                                    @click="$server.uploads.<?= $field ?>.retry(file.id)"
                                >Retry</button>
                                <button
                                    type="button"
                                    @click="$server.uploads.<?= $field ?>.remove(file.id)"
                                >Remove</button>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>
        <?php endforeach; ?>

        <div class="button-row">
            <?= $this->Form->button('Save documents', [
                'class' => 'button-primary',
                ':disabled' => '$server.pending("save")',
            ]) ?>
            <span class="quiet" x-show="$server.pending('save')" x-cloak>
                Waiting for transfers and saving…
            </span>
        </div>
        <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
    <?= $this->Form->end() ?>

    <?php if ($savedFiles !== []): ?>
        <p class="success" role="status">
            Saved <?= (string)count($savedFiles) ?> file(s) into private application storage:
            <?= (string)h(implode(', ', $savedFiles)) ?>.
        </p>
    <?php endif; ?>
</section>
