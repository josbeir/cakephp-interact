<?php
declare(strict_types=1);

namespace Interact\Url;

use Interact\Upload\UploadOperation;
use InvalidArgumentException;

/**
 * Validates page-query context independently of endpoint routing and signed identity.
 * History restoration is an input-only synchronization and can never execute an action.
 */
readonly class RequestContext
{
    public string $query;

    public bool $restoring;

    /**
     * Accept bounded raw query text and an explicit restoration marker.
     *
     * @param array<string, mixed> $request Untrusted update envelope.
     */
    public function __construct(array $request)
    {
        /** @var mixed $query */
        $query = $request['pageQuery'] ?? '';
        /** @var mixed $restoring */
        $restoring = $request['urlRestore'] ?? false;
        if (!is_string($query) || strlen($query) > 1_048_576 || !is_bool($restoring)) {
            throw new InvalidArgumentException('Invalid page query context.');
        }

        $this->query = $query;
        $this->restoring = $restoring;
    }

    /**
     * Prevent navigation input from being combined with persistence or file operations.
     *
     * @param list<mixed> $arguments Validated action argument list.
     */
    public function assertActionFree(?string $action, array $arguments, ?UploadOperation $upload): void
    {
        if ($this->restoring && ($action !== null || $arguments !== [] || $upload instanceof UploadOperation)) {
            throw new InvalidArgumentException('History restoration cannot execute actions or transfers.');
        }
    }
}
