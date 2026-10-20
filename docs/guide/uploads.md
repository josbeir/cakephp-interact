---
title: File uploads
description: Stage files with native inputs, render accepted uploads in PHP, and validate and save through CakePHP.
---

# File uploads

Choose a document, correct a validation error, and save it explicitly. Interact
uploads selected files into private temporary storage; your application decides
when and where to keep them permanently.

Follow the [installation](/installation) and [first island](/getting-started)
setup. The example below saves **one document** through a normal CakePHP entity
and Table. Multiple files and transfer controls are optional additions later on
this page. The broader [demo](/examples) at `/uploads` also shows attachments.

## Declare a file and bind its input

Add an upload property to an island. Import `Upload` from `Interact\Attribute`
and the PSR uploaded-file interface:

```php [Inside the island class]
use Interact\Attribute\Upload;
use Psr\Http\Message\UploadedFileInterface;

#[Upload]
public ?UploadedFileInterface $document = null;
```

Render its native input with CakePHP's FormHelper:

```php [Inside the island element]
<?= $this->Form->control('document', [
    'type' => 'file',
    'label' => 'Main document',
    'x-interact:model' => 'document',
]) ?>
```

Selecting a file starts its transfer immediately. There is no upload action or
callback to write. A completed transfer updates the island through its normal
lifecycle; it does **not** save an application record or move the file into
permanent storage.

File inputs use `x-interact:model` without timing modifiers. Unlike text drafts,
they stage on selection. Upload properties are separate from `#[State]` and
`#[Prop]`: objects and file contents never enter JSON state or snapshots.

## Use accepted files in PHP

The island receives `$this->document` as an uploaded-file object. The element
receives that same object as `$document`, so ordinary PHP can render its name:

```php [Inside the island element]
<?php if ($document !== null): ?>
    <p><?= h($document->getClientFilename()) ?></p>
<?php endif; ?>
```

It implements `UploadedFileInterface`, including `getStream()`, `getSize()`,
`getClientFilename()`, and `moveTo()`. Interact's `TemporaryUpload` implementation
also exposes a read-only opaque `id` for removal controls:

```php
<button
    type="button"
    @click="$server.uploads.document.remove('<?= h($document->id) ?>')"
>
    Remove document
</button>
```

Place this button inside the preceding `$document !== null` block. For a single
file, `$server.uploads.document.remove()` also clears the selection without an ID.
Names are display metadata: escape them and choose your own permanent paths.

### Generate thumbnails through your application

Accepted files are available on the server. Your island's `viewData()` can use
an application-owned image service to prepare a `$thumbnailUrl`, and the element
can render it normally:

```php
<?php if ($thumbnailUrl !== null): ?>
    <img src="<?= h($thumbnailUrl) ?>" alt="Selected image">
<?php endif; ?>
```

Your service supplies this URL through Glide, PHP image processing, a CDN, or
another backend. Interact does not supply thumbnail generation, public file
routes, or preview URLs. Keep authorization and lifetime checks on any route that
serves temporary files; an opaque ID alone does not grant access.

## Validate and save explicitly

Use `newEntity()` and `save()` as you would in a controller. The Table validates
the uploaded file, and the same entity supplies FormHelper's errors.

For this example, create a `documents` table with your normal migration:

| Column     | Type                          |
| ---------- | ----------------------------- |
| `id`       | Auto-incrementing primary key |
| `filename` | String, up to 255 characters  |

`document` is a transient entity field used for validation, **not** a database
column. Create a writable `storage/documents` directory in your application root,
outside the webroot. Files use the generated record ID as their storage name;
the original filename is display metadata only.

::: code-group

```php [Island]
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

        $this->document?->moveTo(ROOT . '/storage/documents/' . $this->record->get('id') . '.upload');
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
```

```php [Element]
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
        <p>Selected: <?= h($document->getClientFilename()) ?></p>
    <?php endif; ?>
    <?php foreach ($errors['_form'] ?? [] as $message): ?>
        <p role="alert"><?= h($message) ?></p>
    <?php endforeach; ?>
    <?= $this->Form->button('Save document') ?>
    <?php if (!$record->isNew()): ?>
        <p role="status">Document saved.</p>
    <?php endif; ?>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
<?= $this->Form->end() ?>
```

```php [Table]
<?php
declare(strict_types=1);

namespace App\Model\Table;

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
```

```php [Mount]
<?= $this->Interact->render('document-upload') ?>
```

:::

On validation failure, `save()` returns false and the entity keeps its errors.
`setErrors($record->getErrors())` also supplies Interact's field feedback and
selects HTTP 422. `viewData()` passes that same entity to FormHelper. Staging
errors are attached there too, so they appear beside the file input.

The file stays staged after validation fails. Correcting the form and submitting
again does not resend its contents. The input's `accept` attribute helps the
chooser; CakePHP checks the actual file's MIME type and size on the server.

On success, the action saves the record and moves the file. `moveTo()` consumes
Interact's temporary file, and clearing `$document` leaves the chooser ready for
another selection.

The private directory and storage name are application choices, not Interact
configuration. Existing applications can keep storage in their normal Table
behavior or service; no CakePHP `Form` class is required. If storage copies the
stream instead of calling `moveTo()`, also clear the upload property after
successful saving so Interact can release its staging record.

:::: details Coordinate database and file-storage failures

The introductory example saves the row before moving the file. If the move
fails, that row already exists. In your application's storage workflow, decide
how to roll back or clean up a partially completed save.

For example, wrap the save and move together so a move exception also rolls back
the database insert:

```php [Inside save(), after creating the entity]
$saved = $documents->getConnection()->transactional(function () use ($documents): bool {
    if (!$documents->save($this->record)) {
        return false;
    }

    $this->document?->moveTo(ROOT . '/storage/documents/' . $this->record->get('id') . '.upload');

    return true;
});
```

Handle a false `$saved` result with the same entity-error branch above, and clear
`$document` only on success. CakePHP's `save()` is already transactional; the
outer transaction extends that boundary around the move. It does not make the
filesystem transactional: file cleanup after a database commit failure still
belongs to your application. An existing storage service or Table behavior can
own this coordination instead of the island.

::::

## Add multiple attachments

Declare an ordered collection and match it with a multiple-file input:

```php [Inside the island class]
/**
 * @var list<UploadedFileInterface>
 */
#[Upload(multiple: true)]
public array $attachments = [];
```

```php [Inside the island element]
<?= $this->Form->control('attachments', [
    'type' => 'file',
    'multiple' => true,
    'x-interact:model' => 'attachments',
]) ?>

<?php foreach ($attachments as $attachment): ?>
    <p><?= h($attachment->getClientFilename()) ?></p>
<?php endforeach; ?>
```

Further chooser selections append to the accepted list. Single-file inputs
replace their previous accepted file only after the replacement succeeds.
Use item IDs for individual removal, or call `.remove()` without an ID to clear
a field. Applications can also clear or retain entries directly in PHP.

## Set limits for each upload field

Give a single document a 1 MiB ceiling and allow up to three smaller attachments:

```php [Inside the island class]
#[Upload(maxFileSize: '1MB')]
public ?UploadedFileInterface $document = null;

/**
 * @var list<UploadedFileInterface>
 */
#[Upload(multiple: true, maxFileSize: '512KB', maxFiles: 3)]
public array $attachments = [];
```

Keep the same FormHelper inputs; no size or count attributes are needed in HTML.
`maxFileSize` accepts integer bytes or CakePHP size strings. `maxFiles` is a
positive integer for `multiple: true` fields and counts accepted files across
chooser selections. Both options are optional and inherit the global settings.
Single-file fields always allow one selected file and can replace it.

Field limits can lower, but cannot raise, `Interact.uploads.maxFileSize` and
`maxFiles`. The effective browser size limit also respects PHP's upload limits.
Global island/session quotas still apply across fields.

Before each queued transfer, the browser checks the file size and available
accepted slots. PHP counts actual stream bytes and enforces the field count
under the session lock too, so changing browser metadata or submitting from an
older page cannot bypass the limits. With a batch larger than the available
slots, earlier accepted files remain selected; excess items fail with feedback
and are not transferred. Remove unwanted items or free a slot and explicitly retry.

These are temporary-storage limits. Keep MIME types, image dimensions, required
files, and other application validation in CakePHP. The demo uses a 1 MiB ceiling
for both fields and a three-file attachment limit.

## Show transfer feedback with Alpine

`$server.uploads` handles browser-local progress and controls. It complements
PHP rendering: a file still transferring is not available to PHP yet.

```html [Inside the island element]
<progress
  max="100"
  x-show="$server.uploads.document.pending"
  :value="$server.uploads.document.progress"
></progress>

<button type="button" @click="$server.uploads.document.cancel()">
  Cancel upload
</button>
```

The demo uses an Alpine loop for unfinished or failed transfers and PHP loops
for accepted files. Each field has:

| Member        | Meaning                                                |
| ------------- | ------------------------------------------------------ |
| `pending`     | At least one queued or active transfer                 |
| `progress`    | Transfer percentage, or `null` when unavailable        |
| `files`       | Display metadata, status, and progress for each item   |
| `cancel(id?)` | Discard unfinished selections, optionally one item     |
| `remove(id?)` | Remove one selected item, or clear the field           |
| `retry(id?)`  | Explicitly retry failed transfers, optionally one item |

Item statuses are `queued`, `uploading`, `ready`, and `failed`. Items have `id`,
`name`, `size`, and `progress`. IDs remain opaque; removal accepts IDs from
browser metadata or the PHP file's `id`. Progress describes bytes transferred;
100% alone does not mean the server accepted the file.

Unqualified `$server.pending()` and `$server.loading()` include uploads.
`$server.pending('save')` still selects the PHP action. Submitting while files
are transferring queues the save behind them; a failed or cancelled prerequisite
prevents that save from executing. Retrying a file never automatically saves.

Failures use the [existing recovery contract](/guide/failures). Expected file
rejections appear in field errors. Transport failures pause automatic work;
reselection or `.retry()` is explicit recovery. No transfer is retried
automatically. Polling skips unfinished or failed selections.

The entity example above attaches staging errors in `viewData()`, so FormHelper
displays them beside the file input too.

Changing a file selection while an action waits also discards that queued action.
Submit again when the intended selection is ready. An action already executing on
the server cannot be cancelled by changing the chooser.

The browser checks the advertised staging ceiling before sending file bytes and
shows a readable limit, such as “Choose a file no larger than 2 MiB.” That
ceiling also respects PHP's `upload_max_filesize` and `post_max_size`; application
validation may impose a smaller limit. A 413 response from PHP or the web server
uses `upload_too_large` feedback, including when PHP discarded the multipart body
before island validation could run. Envelope overhead can also exceed a request
body limit, so server checks remain authoritative.

## Configure private staging and cleanup

Ordinary installations need no storage registration. Configure optional limits
in your application's configuration:

```php [config/app.php — configuration excerpt]
'Interact' => [
    'uploads' => [
        'directory' => TMP . 'interact/uploads',
        'maxFileSize' => '10MB',
        'maxFiles' => 10,
        'maxIslandSize' => '50MB',
        'maxSessionSize' => '100MB',
        'maxSessionFiles' => 100,
        'ttl' => 86_400,
    ],
],
```

Size settings accept integer bytes or human-readable strings such as `'10MB'`
and `'1.5GB'`, parsed by CakePHP's `Text::parseFileSize()`. Counts remain positive
integers and retention is in seconds. These are transport/storage ceilings;
your application can impose smaller limits and its own allowed types. Abandoned
transfers count toward capacity until cleanup. Zero-byte files count toward the
file-count limit too.

Keep staging outside the webroot. Configure PHP's `upload_max_filesize`,
`post_max_size`, and your web server's body limits to accommodate a file plus its
multipart envelope. Those limits can reject a request before application file
validation. The existing 1 MiB JSON-envelope limit remains separate from bytes.

Staging performs bounded expiry cleanup. Also schedule the CakePHP command so
abandoned files are removed when the application is idle:

```sh
bin/cake interact.cleanup_uploads
```

The default retention is 24 hours. Signed snapshots still have their own shorter
lifetime; uploads do not restore a form after reload or extend page expiry.
Missing or expired files block the requested action and ask for reselection,
while preserving ordinary drafts and other available files. Draft export does
not contain file contents or restore chooser selections.

For a different staging backend, replace `UploadStorageInterface` through the
application container. Its contract covers owned staging, restoration, deletion,
expiry, and atomic capacity checks. Permanent storage remains separate. Multiple
application nodes using the default backend need access to the same private
filesystem.
