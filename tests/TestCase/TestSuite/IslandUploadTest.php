<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\TestSuite;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Interact\Test\Fixture\TestSuite\Application;
use Interact\Test\Fixture\Uploads\ProtectedUploadIsland;
use Interact\Test\TestSuite\Uploads\UploadFixture;
use Interact\TestSuite\IslandTestTrait;
use InvalidArgumentException;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\AssertionFailedError;
use TestApp\Island\DocumentUploadIsland;

/**
 * Tests application-facing upload helpers against real private staging and native templates.
 *
 * Each test owns its temporary and saved files. Authorization, upload limits, signed
 * ownership, and application validation run through the same runtime as browser transfers.
 */
class IslandUploadTest extends TestCase
{
    use IslandTestTrait;

    protected UploadFixture $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = new UploadFixture();
        $this->uploads->configure();
        $this->configApplication(Application::class, [CONFIG]);
    }

    protected function tearDown(): void
    {
        try {
            $this->uploads->close();
        } finally {
            parent::tearDown();
        }
    }

    public function testSingleFilesReplaceAndClearWithoutTransportingObjects(): void
    {
        $test = $this->testIsland(DocumentUploadIsland::class)
            ->assertUploaded('document', [])
            ->upload('document', $this->file('first.txt'))
            ->assertUploaded('document', ['first.txt'])
            ->assertSee('first.txt')
            ->sync()
            ->assertUploaded('document', ['first.txt']);
        $first = $test->result()->snapshot;
        $test->upload('document', $this->file('replacement.txt'))
            ->assertUploaded('document', ['replacement.txt']);
        $this->assertNotSame($first, $test->result()->snapshot);
        $this->assertArrayNotHasKey('document', $test->result()->state);
        $test->removeUpload('document')->assertUploaded('document', [])->sync()->assertUploaded('document', []);
    }

    public function testMultipleFilesAppendAndRemoveOnlyTheSelectedReference(): void
    {
        $test = $this->testIsland('DocumentUpload')
            ->set('title', 'Attachment draft')
            ->upload('attachments', $this->file('one.txt'))
            ->upload('attachments', $this->file('two.txt'))
            ->assertUploaded('attachments', ['one.txt', 'two.txt']);
        $id = $test->result()->uploads['attachments']['files'][0]['id'];
        $test->removeUpload('attachments', $id)
            ->assertUploaded('attachments', ['two.txt'])
            ->assertSet('title', 'Attachment draft')
            ->sync()
            ->assertUploaded('attachments', ['two.txt'])
            ->removeUpload('attachments')
            ->assertUploaded('attachments', []);
    }

    public function testFailedReplacementRetainsTheAcceptedFile(): void
    {
        $this->testIsland('DocumentUpload')
            ->upload('document', $this->file('accepted.txt'))
            ->upload('document', $this->file('large.txt', str_repeat('x', 1_048_577)))
            ->assertHasErrors(['document'])
            ->assertUploaded('document', ['accepted.txt'])
            ->sync()
            ->assertHasNoErrors()
            ->assertUploaded('document', ['accepted.txt']);
    }

    public function testFileCountLimitsAreNotBypassed(): void
    {
        $this->testIsland('DocumentUpload')
            ->upload('attachments', $this->file('one.txt'))
            ->upload('attachments', $this->file('two.txt'))
            ->upload('attachments', $this->file('three.txt'))
            ->upload('attachments', $this->file('four.txt'))
            ->assertHasErrors(['attachments'])
            ->assertUploaded('attachments', ['one.txt', 'two.txt', 'three.txt']);
    }

    public function testValidationRetainsSelectionsUntilAnExplicitSuccessfulSave(): void
    {
        $test = $this->testIsland('DocumentUpload')
            ->upload('document', $this->file('report.txt'))
            ->assertSet('savedFiles', []);
        $this->assertDirectoryDoesNotExist($this->uploads->documentsDirectory);
        $test->call('save')
            ->assertHasErrors(['title'])
            ->assertUploaded('document', ['report.txt'])
            ->set('title', 'Report')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('savedFiles', ['report.txt']);
        $this->assertDirectoryExists($this->uploads->documentsDirectory);
    }

    public function testTransfersRecheckAccessBeforeCreatingStorage(): void
    {
        $request = new ServerRequest(['url' => 'http://localhost/uploads']);
        $test = $this->testIsland(ProtectedUploadIsland::class, request: $request);
        try {
            $test->withRequest($request->withAttribute('allowed', false))->upload(
                'document',
                $this->file('private.txt'),
            );
            $this->fail('Revoked access must prevent staging.');
        } catch (ForbiddenException) {
            $this->assertDirectoryDoesNotExist($this->uploads->directory);
            $test->assertUploaded('document', []);
        }
    }

    public function testRemovalRechecksCurrentAccessAndRetainsTheLastSuccessfulResult(): void
    {
        $request = new ServerRequest(['url' => 'http://localhost/uploads']);
        $test = $this->testIsland(ProtectedUploadIsland::class, request: $request)
            ->upload('document', $this->file('private.txt'));
        try {
            $test->withRequest($request->withAttribute('allowed', false))->removeUpload('document');
            $this->fail('Revoked access must prevent removal.');
        } catch (ForbiddenException) {
            $test->withRequest($request)->sync()->assertUploaded('document', ['private.txt']);
        }
    }

    public function testOneIslandCannotRemoveAnotherIslandsReference(): void
    {
        $first = $this->testIsland('DocumentUpload')->upload('document', $this->file('first.txt'));
        $id = $first->result()->uploads['document']['files'][0]['id'];
        $second = $this->testIsland('DocumentUpload')->upload('document', $this->file('second.txt'));
        $this->expectException(InvalidArgumentException::class);
        $second->removeUpload('document', $id);
    }

    public function testUnknownFieldsStillUseRuntimeDeclarationValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->testIsland('DocumentUpload')->upload('title', $this->file('file.txt'));
    }

    public function testUploadAssertionsRejectUnknownFieldsInsteadOfTreatingThemAsEmpty(): void
    {
        $test = $this->testIsland('DocumentUpload');
        $this->expectException(AssertionFailedError::class);
        $test->assertUploaded('unknown', []);
    }

    public function testUploadAssertionsRequireExactSelectionOrder(): void
    {
        $test = $this->testIsland('DocumentUpload')
            ->upload('attachments', $this->file('one.txt'))
            ->upload('attachments', $this->file('two.txt'));
        $this->expectException(AssertionFailedError::class);
        $test->assertUploaded('attachments', ['two.txt', 'one.txt']);
    }

    /**
     * Supply a real in-memory PSR upload without relying on the HTTP upload registry.
     */
    protected function file(string $name, string $content = 'Sample report'): UploadedFile
    {
        $stream = new Stream('php://temp', 'w+');
        $stream->write($content);
        $stream->rewind();

        return new UploadedFile($stream, strlen($content), UPLOAD_ERR_OK, $name, 'text/plain');
    }
}
