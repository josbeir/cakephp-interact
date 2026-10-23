<?php
declare(strict_types=1);

namespace Interact\Http;

use Cake\Http\Exception\HttpException;
use Cake\Http\Exception\InvalidCsrfTokenException;
use Cake\Http\Response;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotFailureReason;
use InvalidArgumentException;
use Throwable;

/**
 * Converts update exceptions into safe versioned JSON without replacing application logging.
 *
 * The controller uses this for expected failures; CakePHP's exception event uses the
 * same contract for middleware and unexpected failures after normal error logging.
 */
class FailureResponder
{
    /**
     * Classify a failure while preserving its HTTP status and exception response headers.
     */
    public function fromException(Throwable $exception): Response
    {
        $status = match (true) {
            $exception instanceof InvalidSnapshotException => 409,
            $exception instanceof InvalidArgumentException => 400,
            $exception instanceof HttpException => (int)$exception->getCode(),
            default => 500,
        };
        $code = match (true) {
            $exception instanceof InvalidSnapshotException => match ($exception->reason) {
                SnapshotFailureReason::Expired => FailureCode::SnapshotExpired,
                SnapshotFailureReason::SessionChanged => FailureCode::SessionChanged,
                SnapshotFailureReason::Invalid => FailureCode::InvalidSnapshot,
            },
            $exception instanceof InvalidCsrfTokenException => FailureCode::CsrfFailed,
            $status === 400 => FailureCode::InvalidRequest,
            $status === 413 => FailureCode::UploadTooLarge,
            $status === 401 => FailureCode::Unauthenticated,
            $status === 403 => FailureCode::Forbidden,
            $status >= 500 => FailureCode::ServerError,
            default => FailureCode::HttpError,
        };
        $response = new Response(['status' => $status]);
        if ($exception instanceof HttpException) {
            foreach ($exception->getHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response->withType('application/json')
            ->withHeader('Interact', 'true')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody(json_encode([
                'version' => 1,
                'error' => ['code' => $code->value, 'message' => $code->message()],
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG));
    }
}
