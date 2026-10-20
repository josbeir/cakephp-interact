---
title: File uploads
description: Stage files with native inputs, render accepted uploads in PHP, and validate and save through CakePHP.
---

# File uploads

Choose a document, correct a validation error, and save it explicitly. Interact
uploads selected files into private temporary storage; your application decides
when and where to keep them permanently.

Follow the [installation](/installation) and [first island](/getting-started)
setup. To try the complete example, [run the demo](/examples) and open `/uploads`.
It accepts PDF and text documents up to 1 MiB each, including optional attachments.

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

Use your application's CakePHP Form, Table validator, or validation service.
The demo delegates both validation and saving to `DocumentForm`:

```php [Inside DocumentUpload::save()]
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
```

Import the application's `DocumentForm` class. Keep the Form in a protected
request-local property and return it through `viewData()` so FormHelper retains
its validation errors. The complete source below shows these declarations.

The Form uses CakePHP's `uploadedFile()` rule to check size and detected MIME
type. The input's `accept` attribute helps the chooser; validation checks the
actual file independently of the supplied name and media type.

On success, the demo's Form calls `moveTo()` with a random filename inside its
own private storage directory. That consumes the temporary upload and clears
its selection on the next render. If your application copies the stream to a
storage service instead, clear the property explicitly after successful saving:

```php [After application-owned storage succeeds]
$this->document = null;
$this->attachments = [];
```

Application validation errors return HTTP 422 and retain staged files. Correcting
a title and submitting again does not resend file contents. If earlier feedback
is useful, validate the upload in the ordinary `updated()` hook.

:::: details Complete island, element, application Form, and mount

::: code-group

<<< @/../tests/test_app/src/Island/DocumentUpload.php [Island]

<<< @/../tests/test_app/templates/element/Interact/document_upload.php [Element]

<<< @/../tests/test_app/src/Form/DocumentForm.php [Application Form]

```php [Page template]
<?= $this->Interact->render('document-upload') ?>
```

:::

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

The demo passes staging errors into its request-local Form with `setErrors()`,
so FormHelper displays them beside the file input too.

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
