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
use Interact\Upload\LocalUploadStorage;
use Interact\Upload\UploadConfig;
use Interact\Upload\UploadManager;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Checks upload authorization before storing bytes and before invoking application actions.
 */
class UploadRuntimeTest extends TestCase
{
    public function testRevokedPermissionRejectsTransfersBeforePrivateStorageIsCreated(): void
    {
        $directory = TMP . 'unauthorized-upload-' . bin2hex(random_bytes(8));
        $config = new UploadConfig(['directory' => $directory]);
        $registry = new Registry(collection: 'explicit');
        $registry->register(ProtectedUploadIsland::class);

        $runtime = new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('k', 32)),
            new CallbackRenderer(static fn(): string => ''),
            uploads: new UploadManager(new LocalUploadStorage($config), $config),
        );
        $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
        $request = new ServerRequest(['session' => $session]);
        $mount = $runtime->mount(
            'protected-upload',
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
            static::assertDirectoryDoesNotExist($directory);
        }

        $this->expectException(ForbiddenException::class);
        $runtime->update(
            ['snapshot' => $mount->snapshot, 'action' => 'save'],
            new IslandContext($request->withUploadedFiles([]), 'default', '/update'),
        );
    }
}
