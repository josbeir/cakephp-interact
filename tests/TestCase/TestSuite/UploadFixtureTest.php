<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\TestSuite;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Interact\Test\TestSuite\UploadFixture;
use Interact\Upload\LocalUploadStorage;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadOwner;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use RuntimeException;

/**
 * Verifies fixture isolation, exception cleanup, and preservation of unrelated application files.
 * Real staging records include hidden locks and symlinks that shallow cleanup would miss.
 */
class UploadFixtureTest extends TestCase
{
    public function testFixturesReserveDifferentDirectoriesWithoutCreatingStorage(): void
    {
        $first = new UploadFixture();
        $second = new UploadFixture();
        static::assertNotSame($first->directory, $second->directory);
        static::assertDirectoryDoesNotExist($first->directory);
        static::assertDirectoryDoesNotExist($second->directory);
        $stream = new Stream('php://temp', 'w+');
        $stream->write('draft');

        $file = new UploadedFile($stream, 5, UPLOAD_ERR_OK, 'draft.txt', 'text/plain');
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $first->directory]));
        $owner = new UploadOwner('session', 'default', 'island', 'file');
        try {
            $staged = $storage->put($file, $owner);
            static::assertSame('draft', $storage->find($staged->id, $owner)?->getStream()->getContents());
            static::assertDirectoryDoesNotExist($second->directory);
        } finally {
            $first->close();
            $second->close();
        }

        static::assertDirectoryDoesNotExist($first->directory);
    }

    public function testCleanupAfterFailureRestoresSettingsAndDoesNotFollowSymlinks(): void
    {
        $clock = DateTime::getTestNow();
        $initial = new DateTime('2026-10-23 12:00:00');
        DateTime::setTestNow($initial);
        Configure::write('Interact.uploads', ['maxFiles' => 3, 'directory' => TMP . 'unrelated-uploads']);
        Configure::write('Interact.snapshots.ttl', 42);
        $fixture = new UploadFixture();
        $outside = new UploadFixture();
        try {
            mkdir($outside->directory, 0o700, true);
            file_put_contents($outside->directory . '/keep.txt', 'unrelated');
            try {
                $fixture->configure();
                $fixture->configure();
                static::assertSame(3, Configure::read('Interact.uploads.maxFiles'));
                static::assertSame($fixture->directory, Configure::read('Interact.uploads.directory'));
                mkdir($fixture->directory . '/.hidden/nested', 0o700, true);
                file_put_contents($fixture->directory . '/.hidden/nested/.lock', 'lock');
                symlink($outside->directory, $fixture->directory . '/linked');
                symlink($outside->directory . '/missing', $fixture->directory . '/dangling');
                DateTime::setTestNow(new DateTime('2026-10-24 12:00:00'));
                throw new RuntimeException('Failed interaction');
            } catch (RuntimeException $exception) {
                static::assertSame('Failed interaction', $exception->getMessage());
            } finally {
                $fixture->close();
            }

            static::assertDirectoryDoesNotExist($fixture->directory);
            static::assertSame('unrelated', file_get_contents($outside->directory . '/keep.txt'));
            static::assertSame(
                ['maxFiles' => 3, 'directory' => TMP . 'unrelated-uploads'],
                Configure::read('Interact.uploads'),
            );
            static::assertSame(42, Configure::read('Interact.snapshots.ttl'));
            static::assertSame($initial, DateTime::getTestNow());
            $fixture->close();
        } finally {
            $outside->close();
            DateTime::setTestNow($clock);
        }
    }

    public function testCleanupRestoresAnAbsentConfigurationGroup(): void
    {
        Configure::delete('Interact.uploads');
        $fixture = new UploadFixture();
        try {
            $fixture->configure();
            static::assertSame($fixture->directory, Configure::read('Interact.uploads.directory'));
        } finally {
            $fixture->close();
        }

        static::assertNull(Configure::read('Interact.uploads'));
        $fixture->close();
    }
}
