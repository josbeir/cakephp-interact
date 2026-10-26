<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Composition\InputTickets;
use Interact\Loading\Initialization;
use Interact\Upload\UploadOperation;
use Interact\Url\RequestContext;
use InvalidArgumentException;

/**
 * Validates the untrusted update envelope before snapshot or application processing begins.
 */
readonly class UpdateRequest
{
    public string $snapshot;

    public string $pageQuery;

    public bool $urlRestore;

    public bool $initialize;

    public ?string $parentInputs;

    /**
     * @var array<string, mixed> Browser edits, still subject to property-level authorization.
     */
    public array $updates;

    public ?string $action;

    /** Browser event selecting named detail binding for an explicitly listening action. */
    public ?EventInvocation $event;

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

        $this->initialize = Initialization::read($request);
        $this->parentInputs = InputTickets::token($request);
        $url = new RequestContext($request);
        $this->pageQuery = $url->query;
        $this->urlRestore = $url->restoring;
        $this->upload = isset($request['upload']) ? new UploadOperation($request['upload']) : null;
        $url->assertActionFree($action, $arguments, $this->upload);
        $this->upload?->assertTransferOnly($action, $arguments);
        $this->snapshot = $snapshot;
        $this->updates = Values::map($updates);
        $this->action = $action;
        $this->event = EventInvocation::read($request['event'] ?? null, $action, $arguments);
        $this->arguments = $arguments;
    }
}
