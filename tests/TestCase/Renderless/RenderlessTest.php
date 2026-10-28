<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Renderless;

use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Action\ActionEffects;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\RenderPolicy;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\Renderless\RenderlessIsland;
use Interact\Test\Fixture\Runtime\CounterIsland;
use Interact\Test\Fixture\Runtime\RuntimeReference;
use Interact\Test\TestSuite\Uploads\UploadStorageTestCase;
use InvalidArgumentException;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use LogicException;
use RuntimeException;

/**
 * Verifies renderless updates retain signed state and child ownership without evaluating views.
 * Validation, authorization, and response effects run through the real runtime.
 */
class RenderlessTest extends UploadStorageTestCase
{
    protected Runtime $runtime;

    protected IslandContext $context;

    protected SnapshotCodec $codec;

    /**
     * Assemble real signing and class resolution with observable nested application rendering.
     */
    protected function setUp(): void
    {
        parent::setUp();
        RenderlessIsland::$views = 0;
        RenderlessIsland::$allowed = true;
        $this->context = new IslandContext(
            new ServerRequest([
                'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
            ]),
            'default',
            '/_interact/update',
        );
        $registry = new Registry();
        $registry->register(RenderlessIsland::class);
        $registry->register(CounterIsland::class);

        $this->codec = new SnapshotCodec(str_repeat('k', 32));
        $reference = new RuntimeReference();
        $this->runtime = new Runtime(
            $registry,
            $this->codec,
            new CallbackRenderer(function ($definition) use ($reference): string {
                if ($definition->declaration->name === 'Renderless') {
                    return $reference->runtime->mount('Counter', ['recordId' => 7], 'child', $this->context)->html;
                }

                return '<p>Child</p>';
            }),
            uploads: $this->uploadFixture->manager(),
        );
        $reference->runtime = $this->runtime;
    }

    /**
     * Read the observer after runtime callbacks have finished.
     */
    protected function views(): int
    {
        return RenderlessIsland::$views;
    }

    public function testStateEventsChildrenAndUploadsSurviveWithoutRendering(): void
    {
        $initial = $this->runtime->mount('Renderless', [], 'parent', $this->context);
        $first = $this->codec->open($initial->snapshot, $this->context->binding(), $this->context->scope);
        $result = $this->runtime->update([
            'snapshot' => $initial->snapshot,
            'action' => 'remember',
            'updates' => ['title' => 'accepted'],
        ], $this->context);
        $this->assertNull($result->payload()['html']);
        $this->assertSame(1, $this->views());
        $this->assertSame(1, $result->state['count']);
        $this->assertSame('accepted', $result->state['title']);
        $this->assertSame([['name' => 'remembered', 'detail' => ['count' => 1]]], $result->events);
        $this->assertArrayHasKey('file', $result->uploads);
        $next = $this->codec->open($result->snapshot, $this->context->binding(), $this->context->scope);
        $this->assertSame($first['children'], $next['children']);
        $this->assertIsArray($next['state']);
        $this->assertSame('accepted', $next['state']['title']);
        $rendered = $this->runtime->update(['snapshot' => $result->snapshot], $this->context);
        $this->assertStringContainsString('data-interact-preserve', $rendered->html);
        $this->assertSame(2, $this->views());
    }

    public function testOwnedUploadsRemainRestorableAcrossRenderlessSaves(): void
    {
        $initial = $this->runtime->mount('Renderless', [], 'parent', $this->context);
        $stream = new Stream('php://temp', 'w+');
        $stream->write('draft');

        $request = $this->context->request->withUploadedFiles([
            'file' => new UploadedFile($stream, 5, UPLOAD_ERR_OK, 'draft.txt', 'text/plain'),
        ]);
        $staged = $this->runtime->update(
            [
                'snapshot' => $initial->snapshot,
                'upload' => ['kind' => 'stage', 'field' => 'file'],
            ],
            new IslandContext($request, 'default', '/_interact/update'),
        );
        $this->assertIsString($staged->payload()['html']);
        $this->assertCount(1, $staged->uploads['file']['files']);
        $saved = $this->runtime->update(['snapshot' => $staged->snapshot, 'action' => 'remember'], $this->context);
        $this->assertNull($saved->payload()['html']);
        $this->assertSame($staged->uploads, $saved->uploads);
        $before = $this->codec->open($staged->snapshot, $this->context->binding(), $this->context->scope);
        $after = $this->codec->open($saved->snapshot, $this->context->binding(), $this->context->scope);
        $this->assertSame($before['uploads'], $after['uploads']);
        $this->assertArrayNotHasKey('file', $saved->state);
        $next = $this->runtime->update(['snapshot' => $saved->snapshot, 'action' => 'remember'], $this->context);
        $this->assertNull($next->payload()['html']);
        $this->assertSame($saved->uploads, $next->uploads);
        $this->assertSame([], $next->errors);
    }

    public function testConditionalChoiceResetsBetweenRequests(): void
    {
        $initial = $this->runtime->mount('Renderless', [], 'parent', $this->context);
        $skipped = $this->runtime->update([
            'snapshot' => $initial->snapshot,
            'action' => 'conditional',
            'args' => [1],
        ], $this->context);
        $this->assertNull($skipped->payload()['html']);
        $rendered = $this->runtime->update([
            'snapshot' => $skipped->snapshot,
            'action' => 'conditional',
            'args' => [5],
        ], $this->context);
        $this->assertIsString($rendered->payload()['html']);
        $this->assertSame(6, $rendered->state['count']);
        $this->assertSame(2, $this->views());
    }

    public function testValidationRendersAndClearsFeedbackBeforeSkippingAgain(): void
    {
        $initial = $this->runtime->mount('Renderless', [], 'parent', $this->context);
        $failed = $this->runtime->update(['snapshot' => $initial->snapshot, 'action' => 'invalid'], $this->context);
        $this->assertIsString($failed->payload()['html']);
        $this->assertSame([], $failed->events);
        $snapshot = $this->codec->open($failed->snapshot, $this->context->binding(), $this->context->scope);
        $this->assertTrue($snapshot['renderedErrors']);
        $cleared = $this->runtime->update(['snapshot' => $failed->snapshot, 'action' => 'remember'], $this->context);
        $this->assertIsString($cleared->payload()['html']);
        $this->assertSame([], $cleared->errors);
        $again = $this->runtime->update(['snapshot' => $cleared->snapshot, 'action' => 'remember'], $this->context);
        $this->assertNull($again->payload()['html']);
        $this->assertSame(3, $this->views());
    }

    public function testDownloadsAndRedirectsKeepTheirOutcomeContracts(): void
    {
        $initial = $this->runtime->mount('Renderless', [], 'parent', $this->context);
        $download = $this->runtime->update(['snapshot' => $initial->snapshot, 'action' => 'download'], $this->context);
        $this->assertSame('/report', $download->download);
        $this->assertNull($download->payload()['html']);
        $this->assertSame(1, $download->state['count']);
        $redirect = $this->runtime->update(['snapshot' => $download->snapshot, 'action' => 'leave'], $this->context);
        $this->assertArrayHasKey('redirect', $redirect->payload());
        $this->assertArrayNotHasKey('snapshot', $redirect->payload());
        $this->assertSame(1, $this->views());
    }

    public function testReadOnlyStateStillRejectsUpdates(): void
    {
        $initial = $this->runtime->mount('Renderless', [], 'parent', $this->context);
        $this->expectException(InvalidArgumentException::class);
        $this->runtime->update([
            'snapshot' => $initial->snapshot,
            'action' => 'remember',
            'updates' => ['count' => 99],
        ], $this->context);
    }

    public function testCurrentPermissionStillRunsBeforeActions(): void
    {
        $initial = $this->runtime->mount('Renderless', [], 'parent', $this->context);
        RenderlessIsland::$allowed = false;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Permission revoked.');
        $this->runtime->update(['snapshot' => $initial->snapshot, 'action' => 'remember'], $this->context);
    }

    public function testSkippingIsActionOnlyAndFailureDoesNotLeakTheChoice(): void
    {
        $effects = new ActionEffects();
        try {
            $effects->capture(static function () use ($effects): void {
                $effects->skipRender();
                throw new RuntimeException('Failed action');
            });
        } catch (RuntimeException) {
            $this->assertTrue($effects->skipsRender());
        }

        $effects->capture(static function (): void {});
        $this->assertFalse($effects->skipsRender());
        $this->expectException(LogicException::class);
        new RenderlessIsland()->skipRender();
    }

    public function testFeedbackMarkerRejectsInvalidSignedTypes(): void
    {
        $this->assertFalse(RenderPolicy::renderedErrors([]));
        $this->assertFalse(RenderPolicy::renderedErrors(['renderedErrors' => false]));
        foreach ([null, 'true', 1, []] as $value) {
            try {
                RenderPolicy::renderedErrors(['renderedErrors' => $value]);
                $this->fail('Invalid feedback metadata was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Invalid signed validation feedback marker.', $exception->getMessage());
            }
        }
    }
}
