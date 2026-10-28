<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Uploads;

use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\I18n\DateTime;
use Interact\Discovery\Definition;
use Interact\IslandContext;
use Interact\Runtime\SnapshotData;
use Interact\Test\TestSuite\Uploads\UploadStorageTestCase;
use Interact\Upload\LocalUploadStorage;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadOwner;
use Interact\Upload\UploadPublisher;
use Interact\Upload\UploadRejected;
use Interact\Upload\UploadRestorer;
use InvalidArgumentException;
use Laminas\Diactoros\UploadedFile;
use TestApp\Island\DocumentUploadIsland as DocumentUpload;

/**
 * Verifies signed selection ownership and safe discovery of damaged or linked upload records.
 */
class UploadBoundaryTest extends UploadStorageTestCase
{
    /**
     * Build a signed-selection identity using a real session and the demo's upload declarations.
     *
     * @param array<string, list<string>> $uploads Selected server identifiers.
     * @return array{Definition, SnapshotData, IslandContext} Verified runtime inputs.
     */
    protected function selection(array $uploads = []): array
    {
        $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
        $context = new IslandContext(new ServerRequest(['session' => $session]), 'default', '/update');
        $snapshot = new SnapshotData([
            'alias' => 'DocumentUpload',
            'id' => 'island',
            'key' => 'file',
            'state' => [],
            'children' => [],
            'uploads' => $uploads,
        ]);

        return [new Definition(DocumentUpload::class), $snapshot, $context];
    }

    public function testUnknownSignedFieldsAreRejectedBeforeStorageLookup(): void
    {
        [$definition, $snapshot, $context] = $this->selection(['unknown' => []]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown signed upload field.');
        new UploadRestorer()->restore(new DocumentUpload(), $definition, $snapshot, $context);
    }

    public function testSingleFileDeclarationsRejectTwoOwnedSignedReferences(): void
    {
        [$definition, $snapshot, $context] = $this->selection();
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        $owner = UploadOwner::fromContext($context, $snapshot, 'document');
        $first = $storage->put($this->incoming(), $owner);
        $second = $storage->put($this->incoming(), $owner);
        [, $selection] = $this->selection(['document' => [$first->id, $second->id]]);
        $island = new DocumentUpload();
        try {
            new UploadRestorer($storage)->restore($island, $definition, $selection, $context);
            $this->fail('A single-file field cannot restore two references.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertSame('Invalid single-file selection.', $invalidArgumentException->getMessage());
            $this->assertNull($island->document);
            $this->assertNotNull($storage->find($first->id, $owner));
            $this->assertNotNull($storage->find($second->id, $owner));
        }
    }

    public function testRenderingRejectsFilesMovedToAnotherFieldByApplicationCode(): void
    {
        [$definition, $snapshot, $context] = $this->selection();
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        $owner = UploadOwner::fromContext($context, $snapshot, 'attachments');
        $island = new DocumentUpload();
        $island->document = $storage->put($this->incoming(), $owner);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The selected file belongs to another upload field.');
        new UploadPublisher($storage)->export($island, $definition, $snapshot, $context);
    }

    public function testExpiryCleanupToleratesMissingBytesAndSkipsLinkedDirectories(): void
    {
        DateTime::setTestNow(new DateTime('2026-10-20 12:00:00'));
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory, 'ttl' => 1]));
        $owner = new UploadOwner('session', 'default', 'island', 'document');
        $file = $storage->put($this->incoming(), $owner);
        $session = $this->directory . '/' . $owner->sessionKey();
        unlink($session . '/' . $file->id . '.bin');
        symlink($session, $this->directory . '/linked-session');
        try {
            DateTime::setTestNow(new DateTime('2026-10-20 12:00:02'));
            $this->assertSame(1, $storage->cleanup());
            $this->assertFileDoesNotExist($session . '/' . $file->id . '.json');
            $this->assertSame(0, $storage->cleanup());
        } finally {
            unlink($this->directory . '/linked-session');
        }
    }

    public function testQuotaDiscoveryIgnoresLinkedMetadataRatherThanFollowingIt(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory, 'maxFiles' => 2]));
        $owner = new UploadOwner('session', 'default', 'island', 'document');
        $file = $storage->put($this->incoming(), $owner);
        $session = $this->directory . '/' . $owner->sessionKey();
        $link = $session . '/' . str_repeat('a', 32) . '.json';
        symlink($session . '/' . $file->id . '.json', $link);
        $second = $storage->put($this->incoming(), $owner);
        $this->assertNotNull($storage->find($second->id, $owner));
        $this->assertNull($storage->find(str_repeat('a', 32), $owner));
    }

    public function testInterruptedPhpUploadsNeverCreatePrivateStaging(): void
    {
        $storage = new LocalUploadStorage(new UploadConfig(['directory' => $this->directory]));
        try {
            $storage->put(
                new UploadedFile('php://temp', 0, UPLOAD_ERR_PARTIAL),
                new UploadOwner('session', 'scope', 'island', 'file'),
            );
            $this->fail('Incomplete requests must not enter staging.');
        } catch (UploadRejected $uploadRejected) {
            $this->assertStringContainsString('could not be uploaded', $uploadRejected->getMessage());
            $this->assertDirectoryDoesNotExist($this->directory);
        }
    }
}
