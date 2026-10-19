<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

/**
 * Exercises signature, expiration, scope/session binding, and malformed JSON before application
 * execution.
 */
class SnapshotCodecTest extends TestCase
{
    public function testRoundTripAndExactExpiryBoundary(): void
    {
        $time = 100;
        $codec = new SnapshotCodec(str_repeat('s', 32), 10, static function () use (&$time): int {
            return $time;
        });
        $token = $codec->seal(['null' => null, 'zero' => 0, 'empty' => '', 'false' => false], 'session', 'admin');
        static::assertSame(
            ['null' => null, 'zero' => 0, 'empty' => '', 'false' => false],
            $codec->open($token, 'session', 'admin'),
        );
        $time = 110;
        $this->expectException(InvalidSnapshotException::class);
        $codec->open($token, 'session', 'admin');
    }

    /**
     * Provide session and scope mismatches for signed-snapshot verification.
     *
     * @return iterable<string, array{string, string}> Untrusted scope/session combinations.
     */
    public static function bindings(): iterable
    {
        yield 'other session' => ['other', 'admin'];
        yield 'other scope' => ['session', 'public'];
    }

    #[DataProvider('bindings')]
    public function testRejectsOtherBindings(string $session, string $scope): void
    {
        $codec = new SnapshotCodec(str_repeat('s', 32));
        $token = $codec->seal([], 'session', 'admin');
        $this->expectException(InvalidSnapshotException::class);
        $codec->open($token, $session, $scope);
    }

    /**
     * Provide malformed and tampered browser tokens for rejection tests.
     *
     * @return iterable<string, array{string}> Malformed public tokens.
     */
    public static function tokens(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['bad.signature'];
        yield 'oversized' => [str_repeat('x', 524_289)];
        yield 'bad signature' => ['e30.' . str_repeat('0', 64)];
    }

    #[DataProvider('tokens')]
    public function testRejectsTampering(#[SensitiveParameter] string $token): void
    {
        $this->expectException(InvalidSnapshotException::class);
        new SnapshotCodec(str_repeat('s', 32))->open($token, 'session', 'admin');
    }

    public function testRejectsInvalidSignedJson(): void
    {
        $key = str_repeat('s', 32);
        $payload = rtrim(strtr(base64_encode('{'), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, hash_hmac('sha256', 'interact.snapshot.v1', $key, true));
        $this->expectException(InvalidSnapshotException::class);
        new SnapshotCodec($key)->open($payload . '.' . $signature, 'session', 'admin');
    }

    public function testRejectsWeakConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotCodec('short');
    }

    public function testRejectsInvalidLifetime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotCodec(str_repeat('s', 32), 0);
    }
}
