<?php
declare(strict_types=1);

namespace Interact\Security;

use Closure;
use InvalidArgumentException;
use JsonException;
use LengthException;
use SensitiveParameter;

/**
 * Signs browser-visible state and verifies its session, scope, and lifetime.
 *
 * The token provides integrity rather than secrecy or permanent resource authorization.
 * Application authorization still runs for every accepted update, and tokens may be
 * replayed while their binding and optional expiry remain valid unless the application
 * adds its own operation guarantees.
 */
class SnapshotCodec
{
    /** Maximum complete token size accepted on both issuance and verification. */
    protected const int MAX_TOKEN_BYTES = 524_288;

    /**
     * @var \Closure(): int Current UTC epoch seconds.
     */
    protected readonly Closure $clock;

    /**
     * Configure a signing secret, optional snapshot lifetime, and deterministic clock.
     *
     * @param (\Closure(): int)|null $clock Injectable clock for deterministic expiry checks.
     */
    public function __construct(
        protected readonly string $key,
        protected readonly ?int $ttl = null,
        ?Closure $clock = null,
    ) {
        if (strlen($key) < 32 || $ttl !== null && $ttl < 1) {
            throw new InvalidArgumentException(
                'Use a signing key of at least 32 bytes and a null or positive snapshot lifetime.',
            );
        }

        $this->clock = $clock ?? time(...);
    }

    /**
     * Issue a signed JSON snapshot bound to the current session and middleware scope.
     *
     * @param array<string, mixed> $data Validated island state and identities.
     * @throws LengthException When the signed token would exceed the verification limit.
     */
    public function seal(array $data, string $binding, string $scope): string
    {
        $json = json_encode([
            'version' => 1,
            'expires' => $this->ttl === null ? null : $this->deadline($this->ttl),
            'binding' => hash_hmac('sha256', $binding, $this->key),
            'scope' => $scope,
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG);
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $token = $payload . '.' . $this->signature($payload);
        if (strlen($token) > static::MAX_TOKEN_BYTES) {
            throw new LengthException(
                'Signed island token exceeds the 512 KiB limit. Reduce transported props and state.',
            );
        }

        return $token;
    }

    /**
     * Verify signature, expiry, session binding, and scope before returning snapshot data.
     *
     * @return array<string, mixed> Verified payload; the runtime subsequently validates its island schema.
     *
     * @throws \Interact\Security\InvalidSnapshotException For malformed, expired, or mismatched snapshots.
     */
    public function open(#[SensitiveParameter] string $token, string $binding, string $scope): array
    {
        $parts = [];
        if (
            strlen($token) > static::MAX_TOKEN_BYTES
            || !preg_match('/^([a-zA-Z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts)
        ) {
            throw new InvalidSnapshotException('Invalid snapshot.');
        }

        if (!hash_equals($this->signature($parts[1]), $parts[2])) {
            throw new InvalidSnapshotException('Invalid snapshot signature.');
        }

        try {
            $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
            /** @var mixed $payload */
            $payload = json_decode($decoded === false ? '' : $decoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidSnapshotException(
                'Invalid snapshot JSON.',
                code: 0,
                previous: $jsonException,
            );
        }

        $envelope = new SnapshotEnvelope($payload);
        $this->verifyEnvelope($envelope, $binding, $scope);

        return $envelope->data;
    }

    /**
     * Compute a finite deadline without issuing an unrepresentable timestamp.
     *
     * @throws InvalidArgumentException When the timestamp exceeds the integer range.
     */
    protected function deadline(int $ttl): int
    {
        $now = ($this->clock)();
        if ($now > PHP_INT_MAX - $ttl) {
            throw new InvalidArgumentException('Snapshot lifetime exceeds the supported timestamp range.');
        }

        return $now + $ttl;
    }

    /**
     * Verify signed claims before specialized codecs apply additional capability limits.
     */
    protected function verifyEnvelope(SnapshotEnvelope $envelope, string $binding, string $scope): void
    {
        $envelope->verify(($this->clock)(), hash_hmac('sha256', $binding, $this->key), $scope);
    }

    /**
     * Domain-separate snapshot signatures from other application uses of the secret.
     */
    protected function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'interact.snapshot.v1', $this->key, true));
    }
}
