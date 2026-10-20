<?php
declare(strict_types=1);

namespace Interact\Test\TestSuite;

use Cake\I18n\DateTime;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Owns isolated private files and clock state for tests of temporary-upload storage.
 *
 * Real streams and directories exercise storage behavior; teardown releases each
 * test's records without touching shared demo or application uploads.
 */
abstract class UploadStorageTestCase extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = TMP . 'upload-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        DateTime::setTestNow();
        foreach (glob($this->directory . '/*/*') ?: [] as $path) {
            unlink($path);
        }

        foreach (glob($this->directory . '/*/.lock') ?: [] as $path) {
            unlink($path);
        }

        foreach (glob($this->directory . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            rmdir($path);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * Construct a real uploaded-file stream whose declared size cannot be trusted.
     */
    protected function incoming(string $content = 'sample document'): UploadedFile
    {
        $stream = new Stream('php://temp', 'w+');
        $stream->write($content);
        $stream->rewind();

        return new UploadedFile($stream, 0, UPLOAD_ERR_OK, '../notes.txt', 'image/png');
    }
}
