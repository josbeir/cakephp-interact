<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Discovery\Definition;
use Interact\Runtime\RenderFrame;
use Interact\Runtime\SnapshotData;
use Interact\Security\InvalidSnapshotException;
use InvalidArgumentException;

/**
 * Validates bound child capabilities for mounts and verified parent updates.
 * Each side remains explicitly typed, writable, and independent of URL ownership.
 */
class BoundValues
{
    /**
     * Read the current PHP parent values while checking writable paths and compatible types.
     *
     * @return array<string, mixed> Initial or accepted parent values, keyed by child property.
     */
    public function values(Relation $relation, Definition $child, RenderFrame $parent): array
    {
        $values = [];
        foreach ($relation->models as $name => $binding) {
            $field = $child->properties[$name] ?? null;
            if ($field === null || !$field->modelable || !$field->writable || $field->url !== null) {
                throw new InvalidArgumentException(
                    'Child bindings require modelable state without a child URL binding.',
                );
            }

            /** @var mixed $value */
            $value = new BoundSource()->read($binding['path'], $field, $parent);

            $field->validate($value);
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * Require exactly the model fields declared in the signed parent relationship.
     *
     * @param array<string, mixed> $values Signed initial or updated model values.
     */
    public function validateModels(array $values, SnapshotData $identity, Definition $definition): void
    {
        $models = $identity->relation->models ?? [];
        if (array_diff_key($models, $values) !== [] || array_diff_key($values, $models) !== []) {
            throw new InvalidSnapshotException('Parent model fields do not match their declared bindings.');
        }

        /** @var mixed $value */

        foreach ($values as $name => $value) {
            $field = $definition->properties[$name] ?? null;
            if ($field === null || !$field->writable || !$field->modelable || $field->url !== null) {
                throw new InvalidSnapshotException('Parent models require declared writable modelable state.');
            }

            $field->validate($value);
        }
    }
}
