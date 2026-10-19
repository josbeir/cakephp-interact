<?php
declare(strict_types=1);

namespace Interact\Security;

use RuntimeException;

/**
 * Rejects expired, malformed, or wrongly scoped state before invoking application code.
 */
class InvalidSnapshotException extends RuntimeException
{
}
