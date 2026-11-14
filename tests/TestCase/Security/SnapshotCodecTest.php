<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Security;

use Interact\Realtime\LeaseCodec;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use Interact\Security\SnapshotEnvelope;
use InvalidArgumentException;
use LengthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

/**
 * Exercises signature, expiration, scope/session binding, and malformed JSON before application
 * execution.
 */
class SnapshotCodecTest extends TestCase
{
    /**
     * Check the common token-size contract for snapshots and subscription-only leases.
     *
     * @return iterable<string, array{SnapshotCodec}>
     */
    public static function codecs(): iterable
    {
        yield 'snapshot' => [new SnapshotCodec(str_repeat('s', 32), clock: static fn(): int => 100)];
        yield 'subscription lease' => [new LeaseCodec(str_repeat('s', 32), clock: static fn(): int => 100)];
    }

    /**
     * Accept the exact token boundary and reject its next byte before returning a token.
     */
    #[DataProvider('codecs')]
    public function testIssuanceAndVerificationShareTheExactSizeBoundary(SnapshotCodec $codec): void
    {
        $empty = $codec->seal(['body' => ''], 'session', 'admin');
        $parts = explode('.', $empty);
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $this->assertIsString($json);
        // The token includes one separator and a 64-character signature.
        $body = str_repeat('x', max(0, intdiv((524_288 - 65) * 3, 4) - strlen($json)));
        $token = $codec->seal(['body' => $body], 'session', 'admin');
        $this->assertSame(524_288, strlen($token));
        $this->assertSame(['body' => $body], $codec->open($token, 'session', 'admin'));
        $this->expectException(LengthException::class);
        $this->expectExceptionMessageMatches('/512 KiB/');
        $codec->seal(['body' => $body . 'x'], 'session', 'admin');
    }

    public function testRoundTripAndExactExpiryBoundary(): void
    {
        $time = 100;
        $codec = new SnapshotCodec(str_repeat('s', 32), 10, static function () use (&$time): int {
            return $time;
        });
        $token = $codec->seal(['null' => null, 'zero' => 0, 'empty' => '', 'false' => false], 'session', 'admin');
        $this->assertSame(
            ['null' => null, 'zero' => 0, 'empty' => '', 'false' => false],
            $codec->open($token, 'session', 'admin'),
        );
        $time = 110;
        $this->expectException(InvalidSnapshotException::class);
        $codec->open($token, 'session', 'admin');
    }

    /**
     * Keep an unlimited snapshot usable after long idle periods and configuration changes.
     */
    public function testUnlimitedSnapshotRetainsItsLifetimeWhenTheReaderConfiguresExpiry(): void
    {
        $time = 100;
        $clock = static function () use (&$time): int {
            return $time;
        };
        $token = new SnapshotCodec(str_repeat('s', 32), clock: $clock)->seal(['draft' => 'keep'], 'session', 'admin');
        $time += 30 * 86_400;
        $this->assertSame(
            ['draft' => 'keep'],
            new SnapshotCodec(str_repeat('s', 32), 1, $clock)->open($token, 'session', 'admin'),
        );
    }

    /**
     * Preserve issued deadlines while applying a changed lifetime only to new tokens.
     */
    public function testFiniteDeadlinesBelongToTokensRatherThanTheCurrentConfiguration(): void
    {
        $time = 100;
        $clock = static function () use (&$time): int {
            return $time;
        };
        $token = new SnapshotCodec(str_repeat('s', 32), 10, $clock)->seal(['draft' => 'keep'], 'session', 'admin');
        $reader = new SnapshotCodec(str_repeat('s', 32), clock: $clock);
        $time = 109;
        $data = $reader->open($token, 'session', 'admin');
        $unlimited = $reader->seal($data, 'session', 'admin');
        $time = 110;
        $this->assertSame($data, $reader->open($unlimited, 'session', 'admin'));
        $this->expectException(InvalidSnapshotException::class);
        $reader->open($token, 'session', 'admin');
    }

    /**
     * Preserve the finite deadline and renew it only when another token is issued.
     */
    public function testIssuingANewSnapshotRenewsAFiniteLifetime(): void
    {
        $time = 100;
        $codec = new SnapshotCodec(str_repeat('s', 32), 10, static function () use (&$time): int {
            return $time;
        });
        $original = $codec->seal(['count' => 0], 'session', 'admin');
        $time = 109;
        $updated = $codec->seal($codec->open($original, 'session', 'admin'), 'session', 'admin');
        $time = 110;
        $this->assertSame(['count' => 0], $codec->open($updated, 'session', 'admin'));
        $time = 119;
        $this->expectException(InvalidSnapshotException::class);
        $codec->open($updated, 'session', 'admin');
    }

    /**
     * Reject unsupported expiry claims rather than treating missing values as unlimited.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidExpiryClaims(): iterable
    {
        yield 'missing' => [[]];
        yield 'string' => [['expires' => '100']];
        yield 'boolean' => [['expires' => false]];
        yield 'float' => [['expires' => 100.5]];
        yield 'array' => [['expires' => []]];
    }

    /**
     * Require explicit null or an integer deadline in every envelope.
     *
     * @param array<string, mixed> $claims Malformed expiry claim.
     */
    #[DataProvider('invalidExpiryClaims')]
    public function testRejectsMalformedExpiryClaims(array $claims): void
    {
        $this->expectException(InvalidSnapshotException::class);
        new SnapshotEnvelope($claims + ['version' => 1, 'binding' => 'session', 'scope' => 'admin', 'data' => []]);
    }

    /**
     * Keep subscription proofs finite even if an otherwise authentic envelope omits a deadline.
     */
    public function testSubscriptionLeasesRejectSignedUnlimitedExpiry(): void
    {
        $key = str_repeat('s', 32);
        $payload = rtrim(
            strtr(
                base64_encode(json_encode([
                    'version' => 1,
                    'expires' => null,
                    'binding' => hash_hmac('sha256', 'session', $key),
                    'scope' => 'admin',
                    'data' => [],
                ], JSON_THROW_ON_ERROR)),
                '+/',
                '-_',
            ),
            '=',
        );
        $signature = hash_hmac('sha256', $payload, hash_hmac('sha256', 'interact.realtime.v1', $key, true));
        $this->expectException(InvalidSnapshotException::class);
        $this->expectExceptionMessageMatches('/Subscription leases require a finite expiry\./');
        new LeaseCodec($key)->open($payload . '.' . $signature, 'session', 'admin');
    }

    /**
     * Fail issuance before an overflowing lifetime becomes an invalid numeric claim.
     */
    public function testRejectsUnrepresentableDeadlines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotCodec(str_repeat('s', 32), PHP_INT_MAX, static fn(): int => 100)->seal([], 'session', 'admin');
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
