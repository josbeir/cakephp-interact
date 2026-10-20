<?php
declare(strict_types=1);

namespace Interact\Security;

use RuntimeException;
use Throwable;

/**
 * Rejects expired, malformed, or wrongly scoped state before invoking application code.
 */
class InvalidSnapshotException extends RuntimeException
{
    /**
     * Preserve the standard exception arguments while exposing a safe failure reason.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly SnapshotFailureReason $reason = SnapshotFailureReason::Invalid,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
