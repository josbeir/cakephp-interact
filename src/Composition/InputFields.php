<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Discovery\Definition;
use Interact\Runtime\SnapshotData;
use Interact\Runtime\Values;
use Interact\Security\InvalidSnapshotException;

/**
 * Validates declared fields inside an already verified parent-input capability.
 * Readonly parent props and writable bound values retain separate transport permissions.
 */
class InputFields
{
    /**
     * Check every field before application code can be constructed.
     *
     * @param array<string, mixed> $data Verified capability payload.
     */
    public function read(array $data, SnapshotData $identity, Definition $definition): ParentValues
    {
        $props = Values::map($data['props'] ?? null);
        $models = Values::map($data['models'] ?? null);
        /** @var mixed $value */
        foreach ($props as $name => $value) {
            $field = $definition->properties[$name] ?? null;
            if ($field === null || !$field->prop || !$field->reactive) {
                throw new InvalidSnapshotException('Parent inputs require reactive props.');
            }

            $field->validate($value);
        }

        new BoundValues()->validateModels($models, $identity, $definition);

        return new ParentValues($props, $models);
    }
}
