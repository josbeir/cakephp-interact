<?php
declare(strict_types=1);

namespace Interact\Runtime;

use InvalidArgumentException;

/**
 * Validates the signed protocol schema before restored data reaches island construction and
 * rendering.
 */
final readonly class SnapshotData
{
    public string $alias;

    public string $id;

    public string $key;

    /**
     * @var array<string, mixed> Declared state and mount props.
     */
    public array $state;

    /**
     * @var array<string, array{id: string, alias: string, props: array<string, mixed>}> Verified direct children.
     */
    public array $children;

    /**
     * Validate signed identity, state, and direct-child records before restoring an island.
     *
     * @param array<string, mixed> $data Cryptographically verified, but not yet schema-checked data.
     */
    public function __construct(array $data)
    {
        if (!is_string($data['alias'] ?? null) || !is_string($data['id'] ?? null) || !is_string($data['key'] ?? null)) {
            throw new InvalidArgumentException('Invalid signed island identity.');
        }

        $this->alias = $data['alias'];
        $this->id = $data['id'];
        $this->key = $data['key'];
        $this->state = Values::map($data['state'] ?? null);
        $children = [];

        /** @var mixed $child */
        foreach (Values::map($data['children'] ?? null) as $key => $child) {
            if (!is_array($child) || !is_string($child['id'] ?? null) || !is_string($child['alias'] ?? null)) {
                throw new InvalidArgumentException('Invalid signed child identity.');
            }

            $children[$key] = [
                'id' => $child['id'],
                'alias' => $child['alias'],
                'props' => Values::map($child['props'] ?? null),
            ];
        }

        $this->children = $children;
    }
}
