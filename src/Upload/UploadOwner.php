<?php
declare(strict_types=1);

namespace Interact\Upload;

use Interact\IslandContext;
use Interact\Runtime\SnapshotData;

/**
 * Identifies the session, route scope, island, and field allowed to use a staged file.
 *
 * Storage uses hashes instead of session identifiers in filesystem paths. Ownership
 * comes from verified snapshots and server route configuration, never file metadata.
 *
 * @consistent-constructor
 */
readonly class UploadOwner
{
    /**
     * Retain the ownership boundary used for staging, restoration, and removal.
     */
    public function __construct(
        public string $session,
        public string $scope,
        public string $island,
        public string $field,
    ) {}

    /**
     * Select a private session directory without exposing its session identifier.
     */
    public function sessionKey(): string
    {
        return hash('sha256', $this->session);
    }

    /**
     * Compare the complete field ownership boundary in constant-sized metadata.
     */
    public function key(): string
    {
        return hash('sha256', json_encode([$this->scope, $this->island, $this->field], JSON_THROW_ON_ERROR));
    }

    /**
     * Group files for a per-island capacity check, independently of their field.
     */
    public function islandKey(): string
    {
        return hash('sha256', json_encode([$this->scope, $this->island], JSON_THROW_ON_ERROR));
    }

    /**
     * Build ownership from verified snapshot identity and the current session.
     */
    public static function fromContext(IslandContext $context, SnapshotData $snapshot, string $field): static
    {
        return new static($context->binding(), $context->scope, $snapshot->id, $field);
    }
}
