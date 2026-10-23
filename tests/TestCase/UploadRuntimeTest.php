<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CallbackRenderer;
use Interact\Test\Fixture\ProtectedUploadIsland;
use Interact\Test\TestSuite\UploadStorageTestCase;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;

/**
 * Checks upload authorization before storing bytes and before invoking application actions.
 */
class UploadRuntimeTest extends UploadStorageTestCase
{
    public function testRevokedPermissionRejectsTransfersBeforePrivateStorageIsCreated(): void
    {
        $registry = new Registry();
        $registry->register(ProtectedUploadIsland::class);

        $runtime = new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('k', 32)),
            new CallbackRenderer(static fn(): string => ''),
            uploads: $this->uploadFixture->manager(),
        );
        $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
        $request = new ServerRequest(['session' => $session]);
        $mount = $runtime->mount(
            'ProtectedUpload',
            [],
            'protected',
            new IslandContext($request, 'default', '/update'),
        );
        $stream = new Stream('php://temp', 'w+');
        $stream->write('private document');

        $request = $request->withAttribute('allowed', false)
            ->withUploadedFiles(['file' => new UploadedFile($stream, 16, UPLOAD_ERR_OK, 'private.txt', 'text/plain')]);
        try {
            $runtime->update(
                ['snapshot' => $mount->snapshot, 'upload' => ['kind' => 'stage', 'field' => 'document']],
                new IslandContext($request, 'default', '/update'),
            );
            static::fail('Revoked access must reject the transfer.');
        } catch (ForbiddenException) {
            static::assertDirectoryDoesNotExist($this->directory);
        }

        $this->expectException(ForbiddenException::class);
        $runtime->update(
            ['snapshot' => $mount->snapshot, 'action' => 'save'],
            new IslandContext($request->withUploadedFiles([]), 'default', '/update'),
        );
    }
}
