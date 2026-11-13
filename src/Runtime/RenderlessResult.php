<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Override;

/**
 * Carries accepted state and effects without requesting a DOM replacement.
 *
 * Initial mounts never use this result; only successful, authorized actions can
 * omit HTML while retaining a fresh signed snapshot and existing child ownership.
 */
readonly class RenderlessResult extends Result
{
    /**
     * Distinguish absent markup from an empty rendered root in the JSON protocol.
     *
     * @return array<string, mixed> State response with an explicit null HTML member.
     */
    #[Override]
    public function payload(): array
    {
        $payload = parent::payload();
        $payload['html'] = null;

        return $payload;
    }
}
