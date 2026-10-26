<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Debugging;

use Cake\Core\Configure;
use Cake\Http\Response;
use Interact\Configuration;
use Interact\Debugging\Diagnostics;
use Interact\Debugging\OutputCapture;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies bounded request output, native buffer ownership, and diagnostic response decoration.
 *
 * Direct bytes and exceptions exercise the same capture boundary used by both transports.
 */
class OutputCaptureTest extends TestCase
{
    public function testCapturePreservesReturnValuesAndNestedTemplateOutput(): void
    {
        $capture = new OutputCapture(true);
        $level = ob_get_level();
        $result = $capture->run(static function (): array {
            echo 'before';
            ob_start();
            echo 'template';
            $html = ob_get_clean();
            echo 'after';

            return ['html' => $html, 'value' => 0];
        });
        static::assertSame(['html' => 'template', 'value' => 0], $result);
        static::assertSame($level, ob_get_level());
        static::assertSame(['output' => 'beforeafter', 'truncated' => false, 'viewer' => true], $capture->take());
        static::assertNull($capture->take());
    }

    public function testFailureRestoresBuffersAndKeepsNativeExceptionIdentity(): void
    {
        $capture = new OutputCapture(false);
        $exception = new RuntimeException('unchanged');
        $level = ob_get_level();
        try {
            $capture->run(static function () use ($exception): never {
                echo 'before exception';
                ob_start();
                echo 'abandoned buffer';

                throw $exception;
            });
        } catch (RuntimeException $runtimeException) {
            static::assertSame($exception, $runtimeException);
        }

        static::assertSame($level, ob_get_level());
        $data = $capture->take();
        static::assertNotNull($data);
        static::assertSame('before exceptionabandoned buffer', $data['output']);
    }

    public function testProtocolOutputBypassesCaptureAndRestoresCaptureAfterWriterFailure(): void
    {
        $capture = new OutputCapture(true);
        ob_start();
        $capture->run(static function () use ($capture): void {
            echo 'diagnostic';
            try {
                $capture->pass(static function (): never {
                    echo 'protocol';

                    throw new RuntimeException('write failed');
                });
            } catch (RuntimeException) {
                echo 'after';
            }
        });
        static::assertSame('protocol', ob_get_clean());
        $data = $capture->take();
        static::assertNotNull($data);
        static::assertSame('diagnosticafter', $data['output']);
        ob_start();
        $capture->pass(static function (): void {
            echo 'outside';
        });
        static::assertSame('outside', ob_get_clean());
    }

    public function testRetentionIsBoundedAcrossDrainsAndMalformedUtf8IsReplaced(): void
    {
        $capture = new OutputCapture(false);
        static::assertNull($capture->take());
        $capture->run(static function (): void {
            echo "\xFF" . str_repeat('x', 262_144);
        });
        $data = $capture->take();
        static::assertNotNull($data);
        static::assertTrue($data['truncated']);
        static::assertFalse($data['viewer']);
        static::assertTrue(mb_check_encoding($data['output'], 'UTF-8'));
        static::assertSame(262_144, mb_strlen($data['output'], 'UTF-8'));
        static::assertNull($capture->take());
        $capture->run(static function (): void {
            echo 'discarded';
        });
        static::assertNull($capture->take());
    }

    public function testConfigurationFollowsApplicationDebugAndCannotOverrideProduction(): void
    {
        /** @var mixed $previous */
        $previous = Configure::read('debug');
        try {
            Configure::write('debug', true);
            static::assertTrue(new Configuration()->debugEnabled);
            static::assertNotNull(new Diagnostics()->begin());
            static::assertNull(new Diagnostics(new Configuration(['debug' => ['enabled' => false]]))->begin());
            $settings = new Configuration(['debug' => ['viewer' => false]]);
            static::assertTrue($settings->debugEnabled);
            static::assertFalse($settings->debugViewer);
            Configure::write('debug', false);
            static::assertFalse(new Configuration()->debugEnabled);
            static::assertFalse(new Configuration(['debug' => ['enabled' => true]])->debugEnabled);
            static::assertNull(new Diagnostics($settings)->begin());
        } finally {
            Configure::write('debug', $previous);
        }
    }

    public function testExceptionDiagnosticsAreConsumedOnceAndNeverSharedWithAnotherFailure(): void
    {
        $diagnostics = new Diagnostics();
        $capture = $diagnostics->begin();
        static::assertNotNull($capture);
        $capture->run(static function (): void {
            echo 'request output';
        });
        $exception = new RuntimeException();
        $other = new RuntimeException();
        $response = new Response(['status' => 422])->withHeader('X-App', 'kept')
            ->withStringBody('{"version":1,"state":{},"errors":{},"events":[]}');
        $diagnostics->remember($exception, $capture);
        $diagnostics->remember($other, null);
        static::assertSame($response, $diagnostics->failure($other, $response));
        $decorated = $diagnostics->failure($exception, $response);
        static::assertSame(422, $decorated->getStatusCode());
        static::assertSame('kept', $decorated->getHeaderLine('X-App'));
        static::assertStringContainsString('request output', (string)$decorated->getBody());
        static::assertStringContainsString('"state":{},"errors":{},"events":[]', (string)$decorated->getBody());
        static::assertSame($response, $diagnostics->failure($exception, $response));
        static::assertSame($response, $diagnostics->response($response, null));
    }

    public function testTurningOffApplicationDebugDiscardsAlreadyCapturedOutput(): void
    {
        $capture = new OutputCapture(true);
        $capture->run(static function (): void {
            echo 'development only';
        });
        /** @var mixed $previous */
        $previous = Configure::read('debug');
        try {
            Configure::write('debug', false);
            $response = new Response()->withStringBody('{}');
            static::assertSame($response, new Diagnostics()->response($response, $capture));
        } finally {
            Configure::write('debug', $previous);
        }
    }
}
