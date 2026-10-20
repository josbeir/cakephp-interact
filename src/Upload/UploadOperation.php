<?php
declare(strict_types=1);

namespace Interact\Upload;

use InvalidArgumentException;

/**
 * Validates internal transfer operations independently of browser-callable PHP actions.
 */
readonly class UploadOperation
{
    public string $field;

    public string $kind;

    public ?string $id;

    /**
     * Accept a declared field operation rather than arbitrary state or object references.
     */
    public function __construct(mixed $value)
    {
        if (
            !is_array($value)
            || !is_string($value['field'] ?? null)
            || !in_array($value['kind'] ?? null, ['stage', 'remove'], true)
        ) {
            throw new InvalidArgumentException('Invalid upload operation.');
        }

        /** @var mixed $id */
        $id = $value['id'] ?? null;
        if ($id !== null && !is_string($id)) {
            throw new InvalidArgumentException('Invalid upload identifier.');
        }

        $this->field = $value['field'];
        $this->kind = $value['kind'];
        $this->id = $id;
    }

    /**
     * Prevent a transfer from also invoking an application action.
     *
     * @param list<mixed> $arguments Action arguments from the checked envelope.
     */
    public function assertTransferOnly(?string $action, array $arguments): void
    {
        if ($action !== null || $arguments !== []) {
            throw new InvalidArgumentException('Uploads cannot invoke actions.');
        }
    }
}
