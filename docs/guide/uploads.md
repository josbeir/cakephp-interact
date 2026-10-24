---
title: 'File uploads'
description: Declare uploaded files, bind native inputs, and configure private staging limits.
---

# File uploads

Interact transfers selected files to private temporary storage and makes accepted
files available as PHP uploaded-file objects.

<span id="declare-a-file-and-bind-its-input"></span>

## #[Upload] {#upload}

Import `Upload` from `Interact\Attribute` and `UploadedFileInterface` from
`Psr\Http\Message`:

```php
#[Upload(maxFileSize: '1MB')]
public ?UploadedFileInterface $document = null;
```

::: code-group

```html [HTML]
<label for="document">Document</label>
<input
  id="document"
  name="document"
  type="file"
  accept=".pdf,.txt"
  x-interact:model="document"
/>
```

```php [FormHelper]
<?= $this->Form->control('document', [
    'type' => 'file',
    'label' => 'Document',
    'accept' => '.pdf,.txt',
    'x-interact:model' => 'document',
]) ?>
```

:::

Selection starts staging immediately. Use `x-interact:model` without timing
modifiers. No upload action is required. An accepted transfer does not save a
record or move the file to permanent storage.

<span id="use-accepted-files-in-php"></span>

## PHP file objects

The island receives `$this->document`; its template receives `$document`. The
object implements `UploadedFileInterface`, including `getStream()`, `getSize()`,
`getClientFilename()`, and `moveTo()`.

```php
<?php if ($document !== null): ?>
    <p><?= h($document->getClientFilename()) ?></p>
<?php endif; ?>
```

Import `Cake\Core\h` in this template. Interact's `TemporaryUpload` also exposes
an opaque readonly `id` for individual removal. Files and references are separate
from writable JSON state; objects and contents are never deserialized from it.

<span id="add-multiple-attachments"></span>

## Multiple files

```php
/**
 * Accepted attachments in selection order.
 *
 * @var list<UploadedFileInterface>
 */
#[Upload(multiple: true, maxFiles: 3)]
public array $attachments = [];
```

::: code-group

```html [HTML]
<label for="attachments">Attachments</label>
<input
  id="attachments"
  name="attachments[]"
  type="file"
  multiple
  x-interact:model="attachments"
/>
```

```php [FormHelper]
<?= $this->Form->control('attachments', [
    'type' => 'file',
    'multiple' => true,
    'x-interact:model' => 'attachments',
]) ?>
```

:::

Further selections append. Single-file fields replace the previous file only
after a replacement succeeds. PHP can clear a property to release its references.

<span id="set-limits-for-each-upload-field"></span>

## Field limits

| Attribute option | Default | Description                                                |
| ---------------- | ------- | ---------------------------------------------------------- |
| `multiple`       | `false` | One nullable file, or an ordered array initialized to `[]` |
| `maxFileSize`    | Inherit | Positive integer bytes or CakePHP size string              |
| `maxFiles`       | Inherit | Positive count for multiple-file fields                    |

Field limits may lower global ceilings, but cannot raise them. Single-file fields
always allow one file and cannot declare `maxFiles`. Browser checks size and
slots before sending; PHP independently checks actual bytes and capacity under
the session lock. Excess items fail without replacing accepted files.

These are staging limits. Validate required files, MIME types, dimensions, and
application rules in CakePHP. The native `accept` attribute is chooser feedback,
not server validation.

<span id="generate-thumbnails-through-your-application"></span>
<span id="validate-and-save-explicitly"></span>

## Validation and saving

Pass the uploaded file as an entity field, validate through the Table, then save
and move it in your application action. Alternatively, use a CakePHP Form for
validation and storage, as in the [demo upload workflow](/demo#uploads).
Accepted files remain staged after validation fails, so another submission does
not resend them. Clear the upload property or replace its accepted file selection after successful storage.

For image thumbnails, prepare a URL with an application image service in
`viewData()`. Interact does not provide image processing or public preview routes.
Authorize routes serving temporary files and choose private storage paths.

<span id="show-transfer-feedback-with-alpine"></span>

## Progress and transfer controls

[`$server.uploads`](/reference/server#uploads) exposes per-field progress and
`cancel()`, `remove()`, and `retry()`. Removal accepts browser item IDs or PHP
`TemporaryUpload::id`. Progress at 100% does not itself prove server acceptance.

Display these values with Alpine's `x-show`, `x-text`, and attribute bindings;
invoke transfer controls from Alpine `@click` handlers. Interact owns the
transfer state, while your application owns its markup and styling. See the
[`$server.uploads` example](/reference/server#uploads).

Saves wait for preceding transfers. Failed/cancelled prerequisites block the
queued save; changing a selection also discards a waiting action. Submit again
when the intended files are ready. Retry never saves automatically. Polling skips
unfinished or failed selections.

Expected file rejections appear as field errors. Transport failures use
[Error handling](/guide/failures). A 413 becomes `upload_too_large`, including
when PHP discards an oversized multipart body before application validation.
Multipart overhead also counts toward server body limits.

<span id="configure-private-staging-and-cleanup"></span>

## Private staging and cleanup

Ordinary installations need no storage registration. See
[temporary upload configuration](/configuration#temporary-uploads) for the staging
directory, limits, retention, accepted size strings, and their defaults.

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
