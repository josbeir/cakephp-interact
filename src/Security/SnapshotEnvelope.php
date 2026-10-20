<?php
declare(strict_types=1);

namespace Interact\Security;

use Interact\Runtime\Values;

/**
 * Validates signed envelope claims and binds them to the current session, scope, and time.
 */
final readonly class SnapshotEnvelope
{
    /**
     * @var array<string, mixed> Cryptographically verified application state.
     */
    public array $data;

    public int $expires;

    public string $binding;

    public string $scope;

    /**
     * Validate the claim schema after cryptographic verification.
     */
    public function __construct(mixed $payload)
    {
        if (
            !is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || !is_int($payload['expires'] ?? null)
            || !is_string($payload['binding'] ?? null)
            || !is_string($payload['scope'] ?? null)
            || !is_array($payload['data'] ?? null)
        ) {
            throw new InvalidSnapshotException('Invalid snapshot envelope.');
        }

        $this->expires = $payload['expires'];
        $this->binding = $payload['binding'];
        $this->scope = $payload['scope'];
        $this->data = Values::map($payload['data']);
    }

    /**
     * Reject stale claims and claims issued for another application context.
     */
    public function verify(int $now, string $binding, string $scope): void
    {
        if ($this->scope !== $scope) {
            throw new InvalidSnapshotException('Snapshot belongs to another scope.');
        }

        if (!hash_equals($this->binding, $binding)) {
            throw new InvalidSnapshotException(
                'Snapshot belongs to another session.',
                reason: SnapshotFailureReason::SessionChanged,
            );
        }

        if ($this->expires <= $now) {
            throw new InvalidSnapshotException('Snapshot expired.', reason: SnapshotFailureReason::Expired);
        }
    }
}
