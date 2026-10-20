<?php
declare(strict_types=1);

namespace Interact\Security;

/**
 * Distinguishes recoverable expiry and session changes after signature verification.
 *
 * Malformed tokens, signature failures, and scope mismatches remain one invalid category.
 */
enum SnapshotFailureReason
{
    case Invalid;
    case Expired;
    case SessionChanged;
}
