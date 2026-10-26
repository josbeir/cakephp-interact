<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Discovery\Property;
use Interact\Island;
use Interact\Runtime\RenderFrame;
use Interact\Runtime\Values;
use InvalidArgumentException;

/**
 * Reads an initialized parent draft path for a child model.
 * Only writable declarations can be sources; nested paths never traverse scalar values.
 */
class BoundSource
{
    /**
     * Require compatible declarations and return the exact parent value.
     */
    public function read(string $path, Property $field, RenderFrame $parent): mixed
    {
        $segments = Values::path($path);
        $source = $parent->definition->properties[array_shift($segments)] ?? null;
        if (
            !$parent->island instanceof Island
            || $source === null
            || !$source->writable
            || !$source->reflection->isInitialized($parent->island)
        ) {
            throw new InvalidArgumentException('Child models require writable parent state.');
        }

        if ($segments === [] && ($source->type !== $field->type || $source->nullable !== $field->nullable)) {
            throw new InvalidArgumentException('Bound parent and child types and nullability must match.');
        }

        /** @var mixed $value */
        $value = $source->read($parent->island);
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new InvalidArgumentException('Initialize parent model paths before mounting a child.');
            }

            /** @var mixed $value */
            $value = $value[$segment];
        }

        return $value;
    }
}
