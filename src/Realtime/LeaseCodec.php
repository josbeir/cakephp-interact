<?php
declare(strict_types=1);

namespace Interact\Realtime;

use Closure;
use Interact\Security\SnapshotCodec;

/**
 * Signs subscription-only leases in a separate cryptographic domain from snapshots.
 *
 * The shared envelope verifies session, scope, and time, while the distinct signature
 * prevents either token type from being used at the other capability's endpoint.
 */
class LeaseCodec extends SnapshotCodec
{
    /**
     * Default leases to five minutes and retain deterministic clock injection for tests.
     *
     * @param (Closure(): int)|null $clock Current UTC epoch seconds.
     */
    public function __construct(string $key, int $ttl = 300, ?Closure $clock = null)
    {
        parent::__construct($key, $ttl, $clock);
    }

    /**
     * Bound provider credentials to the same lifetime as the renewed lease.
     */
    public function expires(): int
    {
        return ($this->clock)() + $this->ttl;
    }

    /**
     * Keep subscription proofs unusable as action, upload, or child capabilities.
     */
    protected function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'interact.realtime.v1', $this->key, true));
    }
}
