<?php
declare(strict_types=1);

namespace Interact\Realtime;

/**
 * Exchanges server-authorized exact topics for expiring provider credentials.
 *
 * The caller has already checked current application access. Implementations must
 * issue subscriber-only grants without exposing publisher secrets.
 */
interface SubscriptionAuthorizerInterface
{
    /**
     * Authorize a deduplicated topic set until the supplied epoch time.
     *
     * @param list<string> $topics Server-selected exact topic identifiers.
     */
    public function authorize(array $topics, int $expires): Grant;
}
