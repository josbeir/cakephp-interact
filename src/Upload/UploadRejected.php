<?php
declare(strict_types=1);

namespace Interact\Upload;

use RuntimeException;

/**
 * Carries an expected transfer rejection that can be shown as field feedback.
 *
 * Storage failures remain ordinary exceptions for application logging; this exception
 * is reserved for safe capacity, expiry, and incoming-upload messages.
 */
class UploadRejected extends RuntimeException
{
}
