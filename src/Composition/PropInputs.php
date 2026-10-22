<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Discovery\Definition;
use InvalidArgumentException;

/**
 * Validates supplied mount props and makes reactive defaults explicit.
 * Ordinary props retain their existing mount-only identity semantics.
 */
class PropInputs
{
    /**
     * Normalize reactive defaults and validate all supplied props before preserving a child.
     *
     * @param array<string, mixed> $props Application-owned mount inputs.
     * @return array<string, mixed> Inputs with reactive defaults made explicit.
     */
    public function props(Definition $definition, array $props): array
    {
        /** @var mixed $value */
        foreach ($props as $name => $value) {
            $field = $definition->properties[$name] ?? null;
            if ($field === null || !$field->prop) {
                throw new InvalidArgumentException('Mount parameters must be declared props.');
            }

            $field->validate($value);
        }

        foreach ($definition->properties as $name => $field) {
            if (!$field->reactive || array_key_exists($name, $props)) {
                continue;
            }

            if (!$field->reflection->hasDefaultValue()) {
                throw new InvalidArgumentException(sprintf('Missing island property "%s".', $name));
            }

            $props[$name] = $field->reflection->getDefaultValue();
        }

        return $props;
    }
}
