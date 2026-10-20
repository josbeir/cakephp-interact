<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\Test\Fixture\UploadFailureStream;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadFilesystem;
use Interact\Upload\UploadOwner;
use Interact\Upload\UploadWriter;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies explicit storage failures and resource cleanup at the native stream boundary.
 */
class UploadFilesystemTest extends TestCase
{
    protected function setUp(): void
    {
        UploadFailureStream::$closed = 0;
        UploadFailureStream::$unlocked = 0;
        stream_wrapper_register('interactuploadfault', UploadFailureStream::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('interactuploadfault');
    }

    public function testDirectoryCreationFailureDoesNotInvokeTheLockedOperation(): void
    {
        $filesystem = new UploadFilesystem(new UploadConfig(['directory' => 'interactuploadfault://mkdir-fail']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create private upload directory.');
        $filesystem->locked(new UploadOwner('session', 'scope', 'island', 'file'), static function (): void {
            static::fail('No operation may run without a private directory.');
        });
    }

    public function testFailedLockReleasesItsHandleWithoutInvokingTheOperation(): void
    {
        $filesystem = new UploadFilesystem(new UploadConfig(['directory' => 'interactuploadfault://lock-fail']));
        $invoked = false;
        try {
            $filesystem->locked(new UploadOwner('session', 'scope', 'island', 'file'), static function () use (
                &$invoked,
            ): void {
                $invoked = true;
            });
            static::fail('A failed lock must reject staging.');
        } catch (RuntimeException $runtimeException) {
            static::assertSame('Cannot lock temporary uploads.', $runtimeException->getMessage());
            static::assertSame(1, UploadFailureStream::$unlocked);
            static::assertSame(1, UploadFailureStream::$closed);
        }
    }

    public function testApplicationFailureInsideALockStillUnlocksAndClosesTheHandle(): void
    {
        $filesystem = new UploadFilesystem(new UploadConfig(['directory' => 'interactuploadfault://operation-fail']));
        $failure = new RuntimeException('Operation failed.');
        try {
            $filesystem->locked(new UploadOwner('session', 'scope', 'island', 'file'), static function () use (
                $failure,
            ): never {
                throw $failure;
            });
        } catch (RuntimeException $runtimeException) {
            static::assertSame($failure, $runtimeException);
            static::assertSame(1, UploadFailureStream::$unlocked);
            static::assertSame(1, UploadFailureStream::$closed);
        }
    }

    public function testShortWritesRejectTheFileAndCloseTheDestination(): void
    {
        $source = new Stream('php://temp', 'w+');
        $source->write('document bytes');

        $file = new UploadedFile($source, 14, UPLOAD_ERR_OK, 'notes.txt');
        try {
            new UploadWriter(new UploadConfig())->copy($file, 'interactuploadfault://short-write');
            static::fail('A short write must not publish a truncated file.');
        } catch (RuntimeException $runtimeException) {
            static::assertSame('Cannot write temporary upload.', $runtimeException->getMessage());
            static::assertSame(1, UploadFailureStream::$closed);
        }
    }
}
