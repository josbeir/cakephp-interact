<?php
declare(strict_types=1);

namespace Interact\Configuration;

use Cake\Utility\Security;
use Interact\Security\SnapshotCodec;
use InvalidArgumentException;

/**
 * Keeps signing configuration server-side while preserving the default security salt.
 * Applications may instead replace the signing service at the container boundary.
 */
readonly class SnapshotSettings
{
    public int $ttl;

    protected ?string $key;

    /**
     * Require a positive lifetime and an optional dedicated secret of sufficient length.
     *
     * @param array<string, mixed> $options Snapshot settings from application configuration.
     * @throws \InvalidArgumentException When the lifetime or dedicated key is invalid.
     */
    public function __construct(array $options = [])
    {
        /** @var mixed $ttl */
        $ttl = array_key_exists('ttl', $options) ? $options['ttl'] : 1800;
        /** @var mixed $key */
        $key = $options['key'] ?? null;
        if (!is_int($ttl) || $ttl < 1 || $key !== null && (!is_string($key) || strlen($key) < 32)) {
            throw new InvalidArgumentException('Invalid Interact snapshot lifetime or signing key.');
        }

        $this->ttl = $ttl;
        $this->key = $key;
    }

    /**
     * Construct a signer without making the configured secret browser-exportable.
     */
    public function codec(): SnapshotCodec
    {
        return new SnapshotCodec($this->key ?? Security::getSalt(), $this->ttl);
    }
}
