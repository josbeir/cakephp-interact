<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\TestSuite;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Interact\Test\TestSuite\Uploads\UploadFixture;
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
        $this->assertNotSame($first->directory, $second->directory);
        $this->assertNotSame($first->documentsDirectory, $second->documentsDirectory);
        $this->assertDirectoryDoesNotExist($first->directory);
        $this->assertDirectoryDoesNotExist($first->documentsDirectory);
        $this->assertDirectoryDoesNotExist($second->directory);
        $stream = new Stream('php://temp', 'w+');
        $stream->write('draft');

        $file = new UploadedFile($stream, 5, UPLOAD_ERR_OK, 'draft.txt', 'text/plain');
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $first->directory]));
        $owner = new UploadOwner('session', 'default', 'island', 'file');
        try {
            $staged = $storage->put($file, $owner);
            $this->assertSame('draft', $storage->find($staged->id, $owner)?->getStream()->getContents());
            $this->assertDirectoryDoesNotExist($second->directory);
        } finally {
            $first->close();
            $second->close();
        }

        $this->assertDirectoryDoesNotExist($first->directory);
    }

    public function testCleanupAfterFailureRestoresSettingsAndDoesNotFollowSymlinks(): void
    {
        $clock = DateTime::getTestNow();
        $initial = new DateTime('2026-10-23 12:00:00');
        DateTime::setTestNow($initial);
        Configure::write('Interact.uploads', ['maxFiles' => 3, 'directory' => TMP . 'unrelated-uploads']);
        Configure::write('Interact.snapshots.ttl', 42);
        Configure::write('Documents', ['directory' => TMP . 'unrelated-documents', 'policy' => 'private']);
        $fixture = new UploadFixture();
        $outside = new UploadFixture();
        try {
            mkdir($outside->directory, 0o700, true);
            file_put_contents($outside->directory . '/keep.txt', 'unrelated');
            try {
                $fixture->configure();
                $fixture->configure();
                $this->assertSame(3, Configure::read('Interact.uploads.maxFiles'));
                $this->assertSame($fixture->directory, Configure::read('Interact.uploads.directory'));
                $this->assertSame($fixture->documentsDirectory, Configure::read('Documents.directory'));
                mkdir($fixture->documentsDirectory, 0o700, true);
                file_put_contents($fixture->documentsDirectory . '/saved.upload', 'saved');
                mkdir($fixture->directory . '/.hidden/nested', 0o700, true);
                file_put_contents($fixture->directory . '/.hidden/nested/.lock', 'lock');
                symlink($outside->directory, $fixture->directory . '/linked');
                symlink($outside->directory . '/missing', $fixture->directory . '/dangling');
                DateTime::setTestNow(new DateTime('2026-10-24 12:00:00'));
                throw new RuntimeException('Failed interaction');
            } catch (RuntimeException $exception) {
                $this->assertSame('Failed interaction', $exception->getMessage());
            } finally {
                $fixture->close();
            }

            $this->assertDirectoryDoesNotExist($fixture->directory);
            $this->assertDirectoryDoesNotExist($fixture->documentsDirectory);
            $this->assertSame('unrelated', file_get_contents($outside->directory . '/keep.txt'));
            $this->assertSame(
                ['maxFiles' => 3, 'directory' => TMP . 'unrelated-uploads'],
                Configure::read('Interact.uploads'),
            );
            $this->assertSame(42, Configure::read('Interact.snapshots.ttl'));
            $this->assertSame(
                ['directory' => TMP . 'unrelated-documents', 'policy' => 'private'],
                Configure::read('Documents'),
            );
            $this->assertSame($initial, DateTime::getTestNow());
            $fixture->close();
        } finally {
            $outside->close();
            DateTime::setTestNow($clock);
        }
    }

    public function testCleanupRestoresAnAbsentConfigurationGroup(): void
    {
        Configure::delete('Interact.uploads');
        Configure::delete('Documents');
        $fixture = new UploadFixture();
        try {
            $fixture->configure();
            $this->assertSame($fixture->directory, Configure::read('Interact.uploads.directory'));
            mkdir($fixture->documentsDirectory, 0o700, true);
            file_put_contents($fixture->documentsDirectory . '/saved.upload', 'saved without staged files');
        } finally {
            $fixture->close();
        }

        $this->assertNull(Configure::read('Interact.uploads'));
        $this->assertNull(Configure::read('Documents'));
        $this->assertDirectoryDoesNotExist($fixture->documentsDirectory);
        $fixture->close();
    }
}
