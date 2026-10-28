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
        $this->assertSame(['html' => 'template', 'value' => 0], $result);
        $this->assertSame($level, ob_get_level());
        $this->assertSame(['output' => 'beforeafter', 'truncated' => false, 'viewer' => true], $capture->take());
        $this->assertNull($capture->take());
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
            $this->assertSame($exception, $runtimeException);
        }

        $this->assertSame($level, ob_get_level());
        $data = $capture->take();
        $this->assertNotNull($data);
        $this->assertSame('before exceptionabandoned buffer', $data['output']);
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
        $this->assertSame('protocol', ob_get_clean());
        $data = $capture->take();
        $this->assertNotNull($data);
        $this->assertSame('diagnosticafter', $data['output']);
        ob_start();
        $capture->pass(static function (): void {
            echo 'outside';
        });
        $this->assertSame('outside', ob_get_clean());
    }

    public function testRetentionIsBoundedAcrossDrainsAndMalformedUtf8IsReplaced(): void
    {
        $capture = new OutputCapture(false);
        $this->assertNull($capture->take());
        $capture->run(static function (): void {
            echo "\xFF" . str_repeat('x', 262_144);
        });
        $data = $capture->take();
        $this->assertNotNull($data);
        $this->assertTrue($data['truncated']);
        $this->assertFalse($data['viewer']);
        $this->assertTrue(mb_check_encoding($data['output'], 'UTF-8'));
        $this->assertSame(262_144, mb_strlen($data['output'], 'UTF-8'));
        $this->assertNull($capture->take());
        $capture->run(static function (): void {
            echo 'discarded';
        });
        $this->assertNull($capture->take());
    }

    public function testConfigurationFollowsApplicationDebugAndCannotOverrideProduction(): void
    {
        /** @var mixed $previous */
        $previous = Configure::read('debug');
        try {
            Configure::write('debug', true);
            $this->assertTrue(new Configuration()->debugEnabled);
            $this->assertNotNull(new Diagnostics()->begin());
            $this->assertNull(new Diagnostics(new Configuration(['debug' => ['enabled' => false]]))->begin());
            $settings = new Configuration(['debug' => ['viewer' => false]]);
            $this->assertTrue($settings->debugEnabled);
            $this->assertFalse($settings->debugViewer);
            Configure::write('debug', false);
            $this->assertFalse(new Configuration()->debugEnabled);
            $this->assertFalse(new Configuration(['debug' => ['enabled' => true]])->debugEnabled);
            $this->assertNull(new Diagnostics($settings)->begin());
        } finally {
            Configure::write('debug', $previous);
        }
    }

    public function testExceptionDiagnosticsAreConsumedOnceAndNeverSharedWithAnotherFailure(): void
    {
        $diagnostics = new Diagnostics();
        $capture = $diagnostics->begin();
        $this->assertNotNull($capture);
        $capture->run(static function (): void {
            echo 'request output';
        });
        $exception = new RuntimeException();
        $other = new RuntimeException();
        $response = new Response(['status' => 422])->withHeader('X-App', 'kept')
            ->withStringBody('{"version":1,"state":{},"errors":{},"events":[]}');
        $diagnostics->remember($exception, $capture);
        $diagnostics->remember($other, null);
        $this->assertSame($response, $diagnostics->failure($other, $response));
        $decorated = $diagnostics->failure($exception, $response);
        $this->assertSame(422, $decorated->getStatusCode());
        $this->assertSame('kept', $decorated->getHeaderLine('X-App'));
        $this->assertStringContainsString('request output', (string)$decorated->getBody());
        $this->assertStringContainsString('"state":{},"errors":{},"events":[]', (string)$decorated->getBody());
        $this->assertSame($response, $diagnostics->failure($exception, $response));
        $this->assertSame($response, $diagnostics->response($response, null));
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
            $this->assertSame($response, new Diagnostics()->response($response, $capture));
        } finally {
            Configure::write('debug', $previous);
        }
    }
}
