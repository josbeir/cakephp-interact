<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Upload\UploadOperation;
use InvalidArgumentException;

/**
 * Validates the untrusted update envelope before snapshot or application processing begins.
 */
readonly class UpdateRequest
{
    public string $snapshot;

    /**
     * @var array<string, mixed> Browser edits, still subject to property-level authorization.
     */
    public array $updates;

    public ?string $action;

    /**
     * Internal staging/removal operation, never a callable PHP action.
     */
    public ?UploadOperation $upload;

    /**
     * @var list<mixed> Explicit action arguments, checked against the exposed method signature.
     */
    public array $arguments;

    /**
     * Check the browser envelope and its size limits before snapshot verification.
     *
     * @param array<string, mixed> $request Raw browser request fields.
     */
    public function __construct(array $request)
    {
        /** @var mixed $snapshot */
        $snapshot = $request['snapshot'] ?? null;
        /** @var mixed $updates */
        $updates = $request['updates'] ?? [];
        /** @var mixed $action */
        $action = $request['action'] ?? null;
        /** @var mixed $arguments */
        $arguments = $request['args'] ?? [];
        if (
            !is_string($snapshot)
            || !is_array($updates)
            || !is_array($arguments)
            || !array_is_list($arguments)
            || $action !== null
            && !is_string($action)
            || count($arguments) > 32
            || count($updates) > 256
        ) {
            throw new InvalidArgumentException('Invalid island update envelope.');
        }

        $this->upload = isset($request['upload']) ? new UploadOperation($request['upload']) : null;
        $this->upload?->assertTransferOnly($action, $arguments);
        $this->snapshot = $snapshot;
        $this->updates = Values::map($updates);
        $this->action = $action;
        $this->arguments = $arguments;
    }
}
