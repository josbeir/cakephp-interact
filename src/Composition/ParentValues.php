<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Discovery\Definition;
use Interact\Island;

/**
 * Holds verified parent inputs for one update, separately from untrusted writable edits.
 *
 * Applying these values changes no application records and runs no hooks. The runtime
 * authorizes the resulting island before reporting changed paths or invoking an action.
 */
readonly class ParentValues
{
    /**
     * Receive values only after the parent ticket and target declarations have been verified.
     *
     * @param array<string, mixed> $props Verified reactive prop replacements.
     * @param array<string, mixed> $models Verified bound state values.
     */
    public function __construct(public array $props = [], public array $models = []) {}

    /**
     * Apply parent-owned props and return only names whose values actually changed.
     *
     * @return list<string> Changed reactive prop names.
     */
    public function apply(Island $island, Definition $definition): array
    {
        $changed = [];
        /** @var mixed $value */
        foreach ($this->props as $name => $value) {
            $field = $definition->properties[$name];
            if ($field->read($island) !== $value) {
                $field->assign($island, $value);
                $changed[] = $name;
            }
        }

        return $changed;
    }
}
