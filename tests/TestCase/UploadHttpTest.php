<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\I18n\DateTime;
use Cake\Utility\Fs\Finder;
use Interact\Test\TestSuite\HttpTestCase;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;

/**
 * Verifies staged files and normal updates through an application-owned CakePHP endpoint.
 *
 * Transfers use actual PSR files and middleware, then continue from the returned
 * signed snapshot. Application Form errors must retain files without another upload.
 */
class UploadHttpTest extends HttpTestCase
{
    /**
     * Send ordinary JSON through the demo's custom handler-backed action.
     *
     * @param array<string, mixed> $data Protocol envelope fields.
     */
    protected function update(array $data): void
    {
        $this->replaceRequest(['headers' => [
            'X-CSRF-Token' => $this->csrf,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]]);
        $this->post('/uploads/update', json_encode(['version' => 1] + $data, JSON_THROW_ON_ERROR));
    }

    /**
     * Stage real bytes with the same multipart envelope sent by the browser.
     */
    protected function stage(
        string $snapshot,
        string $field = 'document',
        string $name = 'notes.txt',
        string $content = 'a plain text document',
    ): void {
        $stream = new Stream('php://temp', 'w+');
        $stream->write($content);
        $stream->rewind();
        $this->replaceRequest([
            'headers' => ['X-CSRF-Token' => $this->csrf, 'Content-Type' => 'multipart/form-data'],
            'files' => ['file' => new UploadedFile($stream, strlen($content), UPLOAD_ERR_OK, $name, 'text/plain')],
        ]);
        $this->post('/uploads/update', ['payload' => json_encode([
            'version' => 1,
            'snapshot' => $snapshot,
            'upload' => ['kind' => 'stage', 'field' => $field],
        ], JSON_THROW_ON_ERROR)]);
    }

    public function testStagingRetainsFilesThroughValidationThenConsumesThemOnlyOnSave(): void
    {
        $mount = $this->mounted(0, '/uploads');
        $this->stage($mount['snapshot']);
        $this->assertResponseOk();
        $this->assertResponseContains('notes.txt');
        $snapshot = $this->nextSnapshot();
        $this->update(['snapshot' => $snapshot, 'action' => 'save']);
        $this->assertResponseCode(422);
        $this->assertResponseContains('Enter a document title.');
        $this->assertResponseContains('notes.txt');
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'updates' => ['title' => 'My document'],
            'action' => 'save',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('Saved 1 file(s)');
        $saved = iterator_to_array(
            new Finder()->in($this->uploadFixture->documentsDirectory)
                ->recursive(false)
                ->files(),
            false,
        );
        static::assertCount(1, $saved);
        static::assertSame('a plain text document', file_get_contents($saved[0]->getPathname()));
        static::assertMatchesRegularExpression(
            '/"document":\{"multiple":false,"maxSize":\d+,"maxFiles":1,"files":\[\]\}/',
            (string)$this->response?->getBody(),
        );
    }

    public function testUnexpectedIncomingFilesCannotBypassExplicitUploadOperations(): void
    {
        $mount = $this->mounted(0, '/uploads');
        $this->replaceRequest([
            'headers' => ['X-CSRF-Token' => $this->csrf, 'Content-Type' => 'multipart/form-data'],
            'files' => ['file' => new UploadedFile(new Stream('php://temp', 'w+'), 0, UPLOAD_ERR_OK, 'unexpected.txt')],
        ]);
        $this->post('/uploads/update', ['payload' => json_encode([
            'version' => 1,
            'snapshot' => $mount['snapshot'],
        ], JSON_THROW_ON_ERROR)]);
        $this->assertResponseCode(400);
        $this->assertResponseContains('invalid_request');
    }

    public function testFieldSizeIsEnforcedWithoutBrowserChecksAndPublishedAsMetadata(): void
    {
        $mount = $this->mounted(0, '/uploads');
        $this->stage($mount['snapshot'], content: str_repeat('x', 1_048_577));
        $this->assertResponseCode(422);
        $this->assertResponseContains('This file exceeds the upload size limit.');
        $this->assertResponseContains('document-error');
        $this->stage($this->nextSnapshot(), content: str_repeat('x', 1_048_576));
        $this->assertResponseOk();
        $this->assertResponseContains('"document":{"multiple":false,"maxSize":1048576,"maxFiles":1,');
        $this->assertResponseContains('"attachments":{"multiple":true,"maxSize":1048576,"maxFiles":3,');
    }

    public function testFieldCountRejectsBothCurrentAndStaleSelectionsWithoutBrowserChecks(): void
    {
        $mount = $this->mounted(0, '/uploads');
        $snapshot = $mount['snapshot'];
        foreach (['one.txt', 'two.txt', 'three.txt'] as $name) {
            $this->stage($snapshot, 'attachments', $name);
            $this->assertResponseOk();
            $snapshot = $this->nextSnapshot();
        }

        $this->stage($snapshot, 'attachments', 'four.txt');
        $this->assertResponseCode(422);
        $this->assertResponseContains('Remove an attachment before adding another file.');
        $this->assertResponseContains('three.txt');
        $this->assertResponseNotContains('four.txt');
        $this->stage($mount['snapshot'], 'attachments', 'stale.txt');
        $this->assertResponseCode(422);
        $this->assertResponseContains('temporary upload capacity has been reached');
        $this->assertResponseNotContains('stale.txt');
    }

    public function testPhpUploadRejectionsRenderThroughTheNativeFormContext(): void
    {
        $mount = $this->mounted(0, '/uploads');
        $this->replaceRequest([
            'headers' => ['X-CSRF-Token' => $this->csrf, 'Content-Type' => 'multipart/form-data'],
            'files' => ['file' => new UploadedFile('php://temp', 0, UPLOAD_ERR_INI_SIZE, 'large.txt', 'text/plain')],
        ]);
        $this->post('/uploads/update', ['payload' => json_encode([
            'version' => 1,
            'snapshot' => $mount['snapshot'],
            'upload' => ['kind' => 'stage', 'field' => 'document'],
        ], JSON_THROW_ON_ERROR)]);
        $this->assertResponseCode(422);
        $this->assertResponseContains('The file exceeds the server upload size limit.');
        $this->assertResponseContains('document-error');
    }

    public function testMultipleSelectionsAppendAndCanBeIndividuallyRemoved(): void
    {
        $mount = $this->mounted(0, '/uploads');
        $this->stage($mount['snapshot'], 'attachments', 'first.txt');
        $this->assertResponseOk();
        $this->stage($this->nextSnapshot(), 'attachments', 'second.txt');
        $this->assertResponseOk();
        /** @var mixed $payload */
        $payload = json_decode((string)$this->response?->getBody(), true, flags: JSON_THROW_ON_ERROR);
        static::assertIsArray($payload);
        /** @var mixed $metadata */
        $metadata = $payload['uploads'];
        static::assertIsArray($metadata);
        /** @var mixed $attachments */
        $attachments = $metadata['attachments'];
        static::assertIsArray($attachments);
        /** @var mixed $files */
        $files = $attachments['files'];
        static::assertIsArray($files);
        static::assertCount(2, $files);
        /** @var mixed $first */
        $first = $files[0];
        static::assertIsArray($first);
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'upload' => ['kind' => 'remove', 'field' => 'attachments', 'id' => $first['id']],
        ]);
        $this->assertResponseOk();
        $this->assertResponseNotContains('first.txt');
        $this->assertResponseContains('second.txt');
        $this->update([
            'snapshot' => $this->nextSnapshot(),
            'upload' => ['kind' => 'remove', 'field' => 'attachments'],
        ]);
        $this->assertResponseOk();
        $this->assertResponseNotContains('second.txt');
    }

    public function testSingleSelectionReplacesAndOldReferencesCannotBeRestored(): void
    {
        $mount = $this->mounted(0, '/uploads');
        $this->stage($mount['snapshot'], name: 'first.txt');
        $old = $this->nextSnapshot();
        $this->stage($old, name: 'replacement.txt');
        $this->assertResponseOk();
        $this->assertResponseNotContains('first.txt');
        $this->assertResponseContains('replacement.txt');
        $this->update(['snapshot' => $old, 'action' => 'save', 'updates' => ['title' => 'Replay']]);
        $this->assertResponseCode(422);
        $this->assertResponseContains('no longer available');
        $this->assertResponseNotContains('Saved 1 file(s)');
    }

    public function testUploadOperationsCannotTargetStateOrInvokeActions(): void
    {
        $mount = $this->mounted(0, '/uploads');
        $this->stage($mount['snapshot'], 'title');
        $this->assertResponseCode(400);
        $this->update([
            'snapshot' => $mount['snapshot'],
            'upload' => ['kind' => 'stage', 'field' => 'document'],
            'action' => 'save',
        ]);
        $this->assertResponseCode(400);
        $this->update(['snapshot' => $mount['snapshot'], 'updates' => ['document' => 'fake-reference']]);
        $this->assertResponseCode(400);
        $this->update(['snapshot' => $mount['snapshot'], 'upload' => ['kind' => 'stage', 'field' => 'document']]);
        $this->assertResponseCode(400);
    }

    public function testCustomEndpointRetainsCsrfScopeAndErrorFormatting(): void
    {
        $mount = $this->mounted(0, '/uploads');
        $this->replaceRequest(['headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json']]);
        $this->post('/uploads/update', json_encode([
            'version' => 1,
            'snapshot' => $mount['snapshot'],
        ], JSON_THROW_ON_ERROR));
        $this->assertResponseCode(403);
        $this->assertResponseContains('csrf_failed');
        $this->replaceRequest(['headers' => [
            'X-CSRF-Token' => $this->csrf,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]]);
        $this->post('/_interact/update', json_encode([
            'version' => 1,
            'snapshot' => $mount['snapshot'],
        ], JSON_THROW_ON_ERROR));
        $this->assertResponseCode(409);
        $this->update(['snapshot' => $mount['snapshot'] . 'tampered']);
        $this->assertResponseCode(409);
    }

    public function testExpiredFilesPreventActionExecutionWithoutLosingTheOrdinaryDraft(): void
    {
        DateTime::setTestNow(new DateTime('2026-10-20 12:00:00'));
        try {
            $mount = $this->mounted(0, '/uploads');
            $this->stage($mount['snapshot']);
            $snapshot = $this->nextSnapshot();
            DateTime::setTestNow(new DateTime('2026-10-21 12:00:01'));
            $this->update(['snapshot' => $snapshot, 'updates' => ['title' => 'Retained title'], 'action' => 'save']);
            $this->assertResponseCode(422);
            $this->assertResponseContains('Retained title');
            $this->assertResponseContains('no longer available');
        } finally {
            DateTime::setTestNow();
        }
    }
}
