<?php
declare(strict_types=1);

namespace Interact\Test\TestSuite;

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

    protected UploadFixture $uploadFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadFixture = new UploadFixture();
        $this->directory = $this->uploadFixture->directory;
    }

    protected function tearDown(): void
    {
        try {
            $this->uploadFixture->close();
        } finally {
            parent::tearDown();
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
