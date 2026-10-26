<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Uploads;

use Cake\Utility\Fs\Finder;
use Cake\Utility\Fs\Path;
use Interact\Test\Fixture\Uploads\UploadMetadataStream;
use Interact\Test\TestSuite\Uploads\UploadStorageTestCase;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadFilesystem;
use Interact\Upload\UploadOwner;
use RuntimeException;
use SplFileInfo;

/**
 * Verifies complete, private metadata publication and preservation of existing records.
 *
 * Real files check permissions; controlled streams exercise interrupted writes and
 * publication failures without changing PHP's error handling.
 */
class UploadMetadataTest extends UploadStorageTestCase
{
    /**
     * @var array{owner: string, island: string, expires: int, size: int, name: string, type: string}
     */
    protected array $record = [
        'owner' => 'owner',
        'island' => 'documents',
        'expires' => 123,
        'size' => 4,
        'name' => 'notes.txt',
        'type' => 'text/plain',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        UploadMetadataStream::$files = [];
        UploadMetadataStream::$observed = [];
        UploadMetadataStream::$shortWrite = false;
        UploadMetadataStream::$rejectRename = false;
        UploadMetadataStream::$closed = 0;
        stream_wrapper_register('interactmetadata', UploadMetadataStream::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('interactmetadata');
        parent::tearDown();
    }

    public function testPublishesACompletePrivateRecordWithoutLeavingStagingFiles(): void
    {
        $filesystem = new UploadFilesystem(new UploadConfig(['directory' => $this->directory]));
        $filesystem->locked(new UploadOwner('session', 'scope', 'island', 'file'), function (string $directory) use (
            $filesystem,
        ): void {
            $path = Path::join($directory, 'record.json');
            $filesystem->write($path, $this->record);
            static::assertSame(json_encode($this->record, JSON_THROW_ON_ERROR), file_get_contents($path));
            static::assertSame(0o600, fileperms($path) & 0o777);
            $entries = array_map(
                static fn(SplFileInfo $entry): string => $entry->getPathname(),
                iterator_to_array(new Finder()->in($directory)->recursive(false)->all(), false),
            );
            sort($entries, SORT_STRING);
            static::assertSame([$path], $entries);
        });
    }

    public function testReadersSeeThePreviousRecordUntilTheCompleteReplacementIsPublished(): void
    {
        $path = 'interactmetadata://record.json';
        UploadMetadataStream::$files[$path] = 'previous record';
        new UploadFilesystem(new UploadConfig())->write($path, $this->record);
        static::assertSame(['previous record'], UploadMetadataStream::$observed);
        static::assertSame([$path => json_encode($this->record, JSON_THROW_ON_ERROR)], UploadMetadataStream::$files);
        static::assertSame(1, UploadMetadataStream::$closed);
    }

    public function testShortWritesNeverPublishPartialMetadataAndCleanUpTheirStagingFile(): void
    {
        UploadMetadataStream::$shortWrite = true;
        $this->assertFailedPublication('Cannot write temporary upload metadata.');
    }

    public function testFailedRenamePreservesThePreviousRecordAndCleansUpTheCompleteStagingFile(): void
    {
        UploadMetadataStream::$rejectRename = true;
        $this->assertFailedPublication('Cannot publish temporary upload metadata.');
    }

    /**
     * Check that a failed replacement retains the previous record and releases its resources.
     */
    protected function assertFailedPublication(string $message): void
    {
        $path = 'interactmetadata://record.json';
        UploadMetadataStream::$files[$path] = 'previous record';
        try {
            new UploadFilesystem(new UploadConfig())->write($path, $this->record);
            static::fail('Incomplete metadata must never be published.');
        } catch (RuntimeException $runtimeException) {
            static::assertSame($message, $runtimeException->getMessage());
            static::assertSame([$path => 'previous record'], UploadMetadataStream::$files);
            static::assertNotEmpty(UploadMetadataStream::$observed);
            static::assertSame(['previous record'], array_values(array_unique(UploadMetadataStream::$observed)));
            static::assertSame(1, UploadMetadataStream::$closed);
        }
    }
}
