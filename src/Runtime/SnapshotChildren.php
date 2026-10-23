<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Composition\Relation;
use Interact\Url\Names;
use InvalidArgumentException;

/**
 * Validates signed direct-child identities without constructing application islands.
 * Relationship configuration and previous parent inputs remain separate from child drafts.
 */
class SnapshotChildren
{
    /**
     * Read verified but not yet schema-checked child records.
     *
     * @return array<string, array{id: string, alias: string, props: array<string, mixed>, urlPrefix?: string, relation?: array{parentId: string, models: array<string, array{path: string, timing: string, delay?: int}>}, values?: array<string, mixed>}>
     */
    public function read(mixed $data): array
    {
        $children = [];

        /** @var mixed $child */
        foreach (Values::map($data) as $key => $child) {
            if (!is_array($child) || !is_string($child['id'] ?? null) || !is_string($child['alias'] ?? null)) {
                throw new InvalidArgumentException('Invalid signed child identity.');
            }

            $children[$key] = [
                'id' => $child['id'],
                'alias' => $child['alias'],
                'props' => Values::map($child['props'] ?? null),
                ...(isset($child['urlPrefix']) ? ['urlPrefix' => Names::require($child['urlPrefix'])] : []),
                ...(isset($child['relation']) ? ['relation' => Relation::read($child['relation'])?->data()] : []),
                ...(isset($child['values']) ? ['values' => Values::map($child['values'])] : []),
            ];
        }

        return $children;
    }
}
