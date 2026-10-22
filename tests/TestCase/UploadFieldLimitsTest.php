<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\Attribute\Upload;
use Interact\Test\Fixture\UploadTypesIsland;
use Interact\Test\TestSuite\UploadStorageTestCase;
use Interact\Upload\LocalUploadStorage;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadOwner;
use Interact\Upload\UploadProperty;
use Interact\Upload\UploadRejected;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Verifies field-specific staging ceilings without weakening application-wide quotas.
 *
 * Declaration checks fail during metadata compilation. Real streams and storage records verify
 * enforcement independently of browser metadata and previously acknowledged selections.
 */
class UploadFieldLimitsTest extends UploadStorageTestCase
{
    /**
     * Supply invalid limits and meaningless count declarations on singular fields.
     *
     * @return iterable<string, array{bool, int|string|null, int|null}> Invalid declaration options.
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero bytes' => [true, 0, null];
        yield 'negative bytes' => [true, -1, null];
        yield 'zero size string' => [true, '0MB', null];
        yield 'invalid size string' => [true, 'invalid', null];
        yield 'empty size string' => [true, '', null];
        yield 'zero count' => [true, null, 0];
        yield 'negative count' => [true, null, -1];
        yield 'single count' => [false, null, 1];
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitsFailBeforeTheDeclarationCanBeUsed(
        bool $multiple,
        int|string|null $maxFileSize,
        ?int $maxFiles,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new Upload($multiple, $maxFileSize, $maxFiles);
    }

    public function testDeclarationsNormalizeSizesAndResolveAgainstGlobalCeilings(): void
    {
        $reflection = new ReflectionProperty(UploadTypesIsland::class, 'multiple');
        $config = new UploadConfig(['maxFileSize' => '2MB', 'maxFiles' => 5]);
        $inherited = new UploadProperty($reflection, new Upload(true));
        static::assertSame(2_097_152, $inherited->fileSize($config));
        static::assertSame(5, $inherited->fileCount($config));
        $smaller = new UploadProperty($reflection, new Upload(true, ' 1.5MB ', 3));
        static::assertSame(1_572_864, $smaller->maxFileSize);
        static::assertSame(1_572_864, $smaller->fileSize($config));
        static::assertSame(3, $smaller->fileCount($config));
        $larger = new UploadProperty($reflection, new Upload(true, '10MB', 10));
        static::assertSame(2_097_152, $larger->fileSize($config));
        static::assertSame(5, $larger->fileCount($config));
        $single = new UploadProperty(new ReflectionProperty(UploadTypesIsland::class, 'single'), new Upload());
        static::assertSame(1, $single->fileCount($config));
    }

    public function testFieldSizeCountsActualBytesAndRemovesRejectedStagingBytes(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        $owner = new UploadOwner('session', 'default', 'island', 'small');
        $accepted = $storage->put($this->incoming('12345'), $owner, maxFileSize: 5);
        try {
            $storage->put($this->incoming('123456'), $owner, maxFileSize: 5);
            static::fail('The actual byte count must respect the field ceiling.');
        } catch (UploadRejected) {
            static::assertNotNull($storage->find($accepted->id, $owner));
            static::assertCount(1, glob($this->directory . '/' . $owner->sessionKey() . '/*.bin') ?: []);
        }
    }

    public function testFieldCountsIncludeUnacknowledgedFilesWithoutSharingSlotsWithOtherFields(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        $owner = new UploadOwner('session', 'default', 'island', 'limited');
        $first = $storage->put($this->incoming(), $owner, maxFiles: 1);
        $other = new UploadOwner('session', 'default', 'island', 'other');
        $otherFile = $storage->put($this->incoming(), $other, maxFiles: 1);
        try {
            $storage->put($this->incoming(), $owner, maxFiles: 1);
            static::fail('A field cannot reuse a slot occupied by an unacknowledged upload.');
        } catch (UploadRejected) {
            static::assertNotNull($storage->find($first->id, $owner));
            static::assertNotNull($storage->find($otherFile->id, $other));
            static::assertCount(2, glob($this->directory . '/' . $owner->sessionKey() . '/*.bin') ?: []);
        }
    }

    public function testSingleReplacementUsesTheSameSlotAtTheFieldLimit(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        $owner = new UploadOwner('session', 'default', 'island', 'single');
        $first = $storage->put($this->incoming('first'), $owner, maxFiles: 1);
        $next = $storage->put($this->incoming('next'), $owner, $first->id, maxFiles: 1);
        static::assertNotNull($storage->find($first->id, $owner));
        static::assertSame('next', $storage->find($next->id, $owner)?->getStream()->getContents());
    }
}
