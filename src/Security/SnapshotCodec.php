<?php
declare(strict_types=1);

namespace Interact\Security;

use Closure;
use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

/**
 * Signs browser-visible state and verifies its session, scope, and lifetime.
 *
 * The token provides integrity rather than secrecy or permanent resource authorization.
 * Application authorization still runs for every accepted update, and tokens may be
 * replayed until expiry unless the application adds its own operation guarantees.
 */
class SnapshotCodec
{
    /**
     * @var \Closure(): int Current UTC epoch seconds.
     */
    protected readonly Closure $clock;

    /**
     * Configure a signing secret, snapshot lifetime, and optional deterministic clock.
     *
     * @param (\Closure(): int)|null $clock Injectable clock for deterministic expiry checks.
     */
    public function __construct(
        protected readonly string $key,
        protected readonly int $ttl = 1800,
        ?Closure $clock = null,
    ) {
        if (strlen($key) < 32 || $ttl < 1) {
            throw new InvalidArgumentException(
                'Use a signing key of at least 32 bytes and a positive snapshot lifetime.',
            );
        }

        $this->clock = $clock ?? time(...);
    }

    /**
     * Issue a signed JSON snapshot bound to the current session and middleware scope.
     *
     * @param array<string, mixed> $data Validated island state and identities.
     */
    public function seal(array $data, string $binding, string $scope): string
    {
        $json = json_encode([
            'version' => 1,
            'expires' => ($this->clock)() + $this->ttl,
            'binding' => hash_hmac('sha256', $binding, $this->key),
            'scope' => $scope,
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG);
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $payload . '.' . $this->signature($payload);
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
        if (strlen($token) > 524_288 || !preg_match('/^([a-zA-Z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts)) {
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
        $envelope->verify(($this->clock)(), hash_hmac('sha256', $binding, $this->key), $scope);

        return $envelope->data;
    }

    /**
     * Domain-separate snapshot signatures from other application uses of the secret.
     */
    protected function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'interact.snapshot.v1', $this->key, true));
    }
}
