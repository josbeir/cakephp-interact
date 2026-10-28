<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Uploads;

use Cake\I18n\DateTime;
use DirectoryIterator;
use Interact\Test\TestSuite\Uploads\UploadStorageTestCase;
use Interact\Upload\LocalUploadStorage;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadFilesystem;
use Interact\Upload\UploadOwner;
use Interact\Upload\UploadPruner;

/**
 * Exercises interrupted staging recovery and bounded request cleanup on a real private filesystem.
 * Session locks and retention protect active or recent files while old orphaned bytes are reclaimed.
 */
class UploadCleanupTest extends UploadStorageTestCase
{
    public function testCleanupReclaimsOldOrphansAndIncompleteMetadataWhileRetainingRecentFiles(): void
    {
        $config = new UploadConfig(['directory' => $this->directory, 'ttl' => 60]);
        $storage = new LocalUploadStorage($config);
        $owner = new UploadOwner('session', 'default', 'island', 'file');
        $file = $storage->put($this->incoming(), $owner);
        $directory = $this->directory . '/' . $owner->sessionKey();
        $base = $directory . '/' . $file->id;
        $now = new DateTime()->getTimestamp();
        unlink($base . '.json');
        touch($base . '.bin', $now - 60);
        $temporary = $directory . '/' . str_repeat('a', 32) . '.json.' . str_repeat('b', 32) . '.tmp';
        file_put_contents($temporary, '{');
        touch($temporary, $now - 60);
        $recent = $directory . '/' . str_repeat('c', 32) . '.bin';
        file_put_contents($recent, 'recent');
        $unrelated = $directory . '/application.txt';
        file_put_contents($unrelated, 'unrelated');
        touch($unrelated, $now - 60);
        static::assertSame(2, $storage->cleanup());
        static::assertFileDoesNotExist($base . '.bin');
        static::assertFileDoesNotExist($temporary);
        static::assertFileExists($recent);
        static::assertFileExists($unrelated);
        static::assertFileExists($directory . '/.lock');
        static::assertSame(0, $storage->cleanup());
    }

    public function testCleanupDoesNotRemovePairedBytesOrFollowAnOrphanSymlink(): void
    {
        $config = new UploadConfig(['directory' => $this->directory, 'ttl' => 60]);
        $storage = new LocalUploadStorage($config);
        $owner = new UploadOwner('session', 'default', 'island', 'file');
        $file = $storage->put($this->incoming(), $owner);
        $directory = $this->directory . '/' . $owner->sessionKey();
        touch($directory . '/' . $file->id . '.bin', time() - 120);
        $linked = $directory . '/' . str_repeat('d', 32) . '.bin';
        symlink($directory . '/' . $file->id . '.bin', $linked);
        static::assertSame(0, $storage->cleanup());
        static::assertNotNull($storage->find($file->id, $owner));
        static::assertTrue(is_link($linked));
    }

    public function testStagingDoesNotScanAnotherSessionsDamagedRecords(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        $other = new UploadOwner('other-session', 'default', 'island', 'file');
        $file = $storage->put($this->incoming(), $other);
        file_put_contents($this->directory . '/' . $other->sessionKey() . '/' . $file->id . '.json', '{}');
        $owner = new UploadOwner('current-session', 'default', 'island', 'file');
        $accepted = $storage->put($this->incoming(), $owner);
        static::assertNotNull($storage->find($accepted->id, $owner));
    }

    public function testScanBudgetCountsLiveAndSkippedEntriesRatherThanOnlyDeletions(): void
    {
        $config = new UploadConfig(['directory' => $this->directory, 'ttl' => 60]);
        $owner = new UploadOwner('session', 'default', 'island', 'file');
        new UploadFilesystem($config)->locked($owner, static function (string $directory) use ($config): void {
            for ($index = 0; $index < 12; $index++) {
                $path = $directory . '/' . str_pad(dechex($index), 32, '0', STR_PAD_LEFT) . '.bin';
                file_put_contents($path, 'abandoned');
                touch($path, time() - 120);
            }

            // Mark the actual first five entries as recent, independent of filesystem ordering.
            $index = 0;
            foreach (new DirectoryIterator($directory) as $entry) {
                if ($index++ >= 5) {
                    break;
                }

                if ($entry->isFile()) {
                    touch($entry->getPathname());
                }
            }

            $pruner = new UploadPruner($config);
            static::assertSame(0, $pruner->prune($directory, scanLimit: 5));
            static::assertGreaterThan(0, $pruner->prune($directory));
        });
    }

    public function testStagingReclaimsItsOwnExpiredQuotaBeforeAcceptingAnotherFile(): void
    {
        DateTime::setTestNow(new DateTime('2026-10-20 12:00:00'));
        $storage = new LocalUploadStorage(new UploadConfig([
            'directory' => $this->directory,
            'ttl' => 1,
            'maxFiles' => 1,
        ]));
        $owner = new UploadOwner('session', 'default', 'island', 'file');
        $first = $storage->put($this->incoming(), $owner);
        DateTime::setTestNow(new DateTime('2026-10-20 12:00:02'));
        $second = $storage->put($this->incoming(), $owner);
        static::assertNull($storage->find($first->id, $owner));
        static::assertNotNull($storage->find($second->id, $owner));
    }
}
