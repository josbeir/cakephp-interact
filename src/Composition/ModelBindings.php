<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Discovery\Definition;
use Interact\Discovery\Property;
use Interact\Runtime\RenderFrame;
use Interact\Runtime\SnapshotData;
use InvalidArgumentException;

/**
 * Resolves mount bindings against declared child capabilities and the active parent render.
 *
 * Only initialized writable parent paths supply model values. Native template variables
 * never become implicit sources, and all values retain their declared JSON types.
 */
class ModelBindings
{
    /**
     * Validate native props and parent bindings before preserving or constructing a child.
     *
     * @param array<string, mixed> $props Declared application mount props.
     * @param array<string, mixed>|string|null $model Explicit parent paths.
     */
    public function prepare(
        array $props,
        string|array|null $model,
        Definition $child,
        ?RenderFrame $parent,
    ): MountValues {
        $props = new PropInputs()->props($child, $props);
        $binding = $this->resolveBinding($model, $child, $parent);

        return new MountValues($props, $binding['values'], $binding['relation']);
    }

    /**
     * Expand the convenient single-value form and validate both sides before child construction.
     *
     * @param array<string, mixed>|string|null $model Application-owned mount option.
     */
    public function resolve(string|array|null $model, Definition $child, ?RenderFrame $parent): ?Relation
    {
        return $this->resolveBinding($model, $child, $parent)['relation'];
    }

    /**
     * Resolve ownership and validate bound values once, before constructing application islands.
     *
     * @param array<string, mixed>|string|null $model Application-owned mount option.
     * @return array{relation: Relation|null, values: array<string, mixed>} Checked preparation inputs.
     */
    protected function resolveBinding(string|array|null $model, Definition $child, ?RenderFrame $parent): array
    {
        $reactive = array_filter($child->properties, static fn(Property $property): bool => $property->reactive);
        if ($model === null && ($reactive === [] || !$parent?->identity instanceof SnapshotData)) {
            return ['relation' => null, 'values' => []];
        }

        if (!$parent?->identity instanceof SnapshotData) {
            throw new InvalidArgumentException('Child models require a direct parent island.');
        }

        if (is_string($model)) {
            $fields = array_filter($child->properties, static fn(Property $property): bool => $property->modelable);
            if (count($fields) !== 1) {
                throw new InvalidArgumentException('String model bindings require exactly one modelable property.');
            }

            $model = [array_key_first($fields) => $model];
        }

        $relation = new Relation($parent->identity->id, $model ?? []);

        return ['relation' => $relation, 'values' => $this->values($relation, $child, $parent)];
    }

    /**
     * Resolve values through the same strict binding validator used for signed updates.
     *
     * @return array<string, mixed> Values keyed by child property.
     */
    public function values(Relation $relation, Definition $child, RenderFrame $parent): array
    {
        return new BoundValues()->values($relation, $child, $parent);
    }
}
