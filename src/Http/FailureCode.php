<?php
declare(strict_types=1);

namespace Interact\Http;

use function Cake\I18n\__d;

/**
 * Names expected update failures without exposing exception details to the browser.
 *
 * Messages describe recovery rather than internal exception or snapshot contents.
 */
enum FailureCode: string
{
    case SnapshotExpired = 'snapshot_expired';
    case SessionChanged = 'session_changed';
    case InvalidSnapshot = 'invalid_snapshot';
    case Unauthenticated = 'unauthenticated';
    case CsrfFailed = 'csrf_failed';
    case Forbidden = 'forbidden';
    case InvalidRequest = 'invalid_request';
    case ServerError = 'server_error';
    case HttpError = 'http_error';

    /**
     * Provide a safe translated message instead of reflecting an exception's message.
     */
    public function message(): string
    {
        return match ($this) {
            self::SnapshotExpired => __d('interact', 'This page has expired. Preserve your draft, then reload.'),
            self::SessionChanged => __d('interact', 'Your session has changed. Preserve your draft, then reload.'),
            self::InvalidSnapshot => __d(
                'interact',
                'This page can no longer update. Preserve your draft, then reload.',
            ),
            self::Unauthenticated => __d('interact', 'Sign in again, then reload this page.'),
            self::CsrfFailed => __d(
                'interact',
                'The security token is no longer valid. Preserve your draft, then reload.',
            ),
            self::Forbidden => __d('interact', 'You no longer have permission to perform this operation.'),
            self::InvalidRequest => __d(
                'interact',
                'The update was rejected. Check your input before submitting again.',
            ),
            self::ServerError => __d(
                'interact',
                'The server could not complete the response. Check the result before trying again.',
            ),
            self::HttpError => __d('interact', 'The update could not be completed.'),
        };
    }
}
