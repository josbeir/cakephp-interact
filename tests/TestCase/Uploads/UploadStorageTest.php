<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Uploads;

use Cake\I18n\DateTime;
use Cake\Utility\Fs\Finder;
use Cake\Utility\Fs\Path;
use Cake\Validation\Validation;
use Interact\Test\TestSuite\Uploads\UploadStorageTestCase;
use Interact\Upload\LocalUploadStorage;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadOwner;
use Interact\Upload\UploadRejected;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;
use RuntimeException;

/**
 * Exercises private filesystem staging, real PSR file streams, ownership, quotas, and expiry.
 *
 * Each test owns a private directory and clock; assertions cover observable storage
 * behavior rather than relying on implementation-specific call counts.
 */
class UploadStorageTest extends UploadStorageTestCase
{
    public function testNativeValidationAndMoveReleaseTheStagingRecord(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        $owner = new UploadOwner('session', 'default', 'island', 'document');
        $file = $storage->put($this->incoming(), $owner);
        $this->assertSame('notes.txt', $file->getClientFilename());
        $this->assertSame(15, $file->getSize());
        $this->assertTrue(Validation::uploadedFile($file, ['types' => ['text/plain']]));
        $this->assertFalse(Validation::uploadedFile($file, ['types' => ['image/png']]));
        $this->assertSame('sample document', $storage->find($file->id, $owner)?->getStream()->getContents());
        $this->assertSame(['id' => $file->id, 'name' => 'notes.txt', 'size' => 15], $file->metadata());
        $destination = $this->directory . '/saved.txt';
        try {
            $file->moveTo('');
            $this->fail('An empty destination must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertNotNull($storage->find($file->id, $owner));
        }

        $file->moveTo($destination);
        $this->assertTrue($file->consumed());
        $this->assertSame('sample document', file_get_contents($destination));
        $this->assertNull($storage->find($file->id, $owner));
        $storage->delete($file->id, $owner);
        unlink($destination);
    }

    public function testOwnershipAndIdentifiersCannotBeChanged(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        $owner = new UploadOwner('session', 'default', 'island', 'document');
        $file = $storage->put($this->incoming(), $owner);
        foreach ([
            new UploadOwner('other', 'default', 'island', 'document'),
            new UploadOwner('session', 'admin', 'island', 'document'),
            new UploadOwner('session', 'default', 'other', 'document'),
            new UploadOwner('session', 'default', 'island', 'other'),
        ] as $intruder) {
            $this->assertNull($storage->find($file->id, $intruder));
            $storage->delete($file->id, $intruder);
        }

        $this->assertNull($storage->find('../outside', $owner));
        $storage->delete('../outside', $owner);
        $this->assertNotNull($storage->find($file->id, $owner));
        $storage->delete($file->id, $owner);
        $this->assertNull($storage->find($file->id, $owner));
    }

    public function testLimitsCountActualBytesAndRetainExistingFilesOnRejection(): void
    {
        foreach ([
            'maxFileSize' => 5,
            'maxSessionSize' => 10,
            'maxIslandSize' => 10,
            'maxFiles' => 1,
            'maxSessionFiles' => 1,
        ] as $setting => $limit) {
            $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory, $setting => $limit]));
            $owner = new UploadOwner($setting, 'default', 'island', 'document');
            $first = $storage->put($this->incoming('first'), $owner);
            try {
                $storage->put($this->incoming('second'), $owner, maxFileSize: 1_000_000, maxFiles: 1000);
                $this->fail('The configured capacity must reject a second file.');
            } catch (UploadRejected) {
                $this->assertNotNull($storage->find($first->id, $owner));
                $this->assertCount(
                    1,
                    new Finder()->in(Path::join($this->directory, $owner->sessionKey()))
                        ->recursive(false)
                        ->name('*.bin')
                        ->all(),
                );
            }
        }
    }

    public function testSingleFileReplacementDoesNotConsumeAnotherFieldSlot(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory, 'maxFiles' => 1]));
        $owner = new UploadOwner('replacement', 'default', 'island', 'document');
        $first = $storage->put($this->incoming('first'), $owner);
        $second = $storage->put($this->incoming('second'), $owner, $first->id);
        $this->assertNotNull($storage->find($first->id, $owner));
        $this->assertNotNull($storage->find($second->id, $owner));
        $storage->delete($first->id, $owner);
        $this->assertSame('second', $storage->find($second->id, $owner)?->getStream()->getContents());
    }

    public function testExpiryAndBoundedCleanupRespectActiveSessionLocks(): void
    {
        DateTime::setTestNow(new DateTime('2026-10-20 12:00:00'));
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory, 'ttl' => 1]));
        $owner = new UploadOwner('session', 'default', 'island', 'document');
        $first = $storage->put($this->incoming(), $owner);
        $storage->put($this->incoming(), $owner);
        $this->assertSame(0, $storage->cleanup());
        DateTime::setTestNow(new DateTime('2026-10-20 12:00:02'));
        $this->assertNull($storage->find($first->id, $owner));
        $lock = fopen($this->directory . '/' . $owner->sessionKey() . '/.lock', 'c');
        $this->assertIsResource($lock);
        flock($lock, LOCK_EX);
        $this->assertSame(0, $storage->cleanup());
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame(1, $storage->cleanup(1));
        $this->assertSame(1, $storage->cleanup());
        $this->assertSame(0, $storage->cleanup());
    }

    public function testIncomingPhpErrorsAndCorruptedMetadataFailSafely(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        $owner = new UploadOwner('session', 'default', 'island', 'document');
        try {
            $storage->put(new UploadedFile('php://temp', null, UPLOAD_ERR_INI_SIZE), $owner);
            $this->fail('PHP upload errors must not become accepted files.');
        } catch (UploadRejected) {
            $this->assertDirectoryDoesNotExist($this->directory);
        }

        $file = $storage->put($this->incoming(), $owner);
        file_put_contents($this->directory . '/' . $owner->sessionKey() . '/' . $file->id . '.json', '{}');
        $this->expectException(RuntimeException::class);
        $storage->find($file->id, $owner);
    }

    public function testHumanReadableSizesAndIntegerBytesNormalizeBeforeEnforcingLimits(): void
    {
        $config = new UploadConfig([
            'directory' => $this->directory,
            'maxFileSize' => ' 1KB ',
            'maxIslandSize' => '1.5MB',
            'maxSessionSize' => 2_097_152,
        ]);
        $this->assertSame(1024, $config->maxFileSize);
        $this->assertSame(1_572_864, $config->maxIslandSize);
        $this->assertSame(2_097_152, $config->maxSessionSize);
        $storage = new LocalUploadStorage($config);
        $owner = new UploadOwner('session', 'default', 'island', 'document');
        $this->expectException(UploadRejected::class);
        $storage->put($this->incoming(str_repeat('x', 1025)), $owner);
    }

    public function testConfigurationRejectsInvalidDirectoriesAndLimits(): void
    {
        foreach ([
            ['directory' => ''],
            ['maxFileSize' => 0],
            ['maxFileSize' => 'invalid'],
            ['maxIslandSize' => '0MB'],
            ['maxSessionSize' => false],
            ['ttl' => '3600'],
        ] as $options) {
            try {
                new UploadConfig($options);
                $this->fail('Invalid configuration must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('upload', strtolower($exception->getMessage()));
            }
        }
    }
}
