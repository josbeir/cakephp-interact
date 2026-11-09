<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authorization;

/**
 * Supplies a policy resource whose availability is independent of signed island state.
 */
class Resource
{
    /**
     * Distinguish protected resources from resources intentionally available to guests.
     */
    public function __construct(public bool $allowed = true, public bool $public = false) {}
}
