<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Discovery\Definition;
use Interact\Island;
use InvalidArgumentException;

/**
 * Assigns initial props and restores signed values on a fresh island instance.
 *
 * Only compiled public properties participate. Mount props and restored state follow
 * separate entry points, leaving browser-write permissions to the update-state service.
 */
class Hydrator
{
    /**
     * Share the runtime's state contract when checking restored values.
     */
    public function __construct(protected readonly State $state = new State()) {}

    /**
     * Assign only properties present in the compiled declaration, preserving their native types.
     *
     * @param array<string, mixed> $values Mount props or previously signed state.
     */
    protected function assign(Island $island, Definition $definition, array $values): void
    {
        /** @var mixed $value */
        foreach ($values as $name => $value) {
            $property = $definition->properties[$name] ?? throw new InvalidArgumentException(
                'Unknown island property.',
            );
            $property->assign($island, $value);
        }
    }

    /**
     * Assign server-owned mount props and require every prop without a default.
     *
     * @param array<string, mixed> $values Explicit initial props; initial state comes from PHP defaults.
     */
    public function mount(Island $island, Definition $definition, array $values): void
    {
        foreach (array_keys($values) as $name) {
            $property = $definition->properties[$name] ?? null;
            if ($property === null || !$property->prop) {
                throw new InvalidArgumentException('Mount parameters must be declared props.');
            }
        }

        $this->assign($island, $definition, $values);
        foreach ($definition->properties as $property) {
            if ($property->prop && !$property->reflection->isInitialized($island)) {
                throw new InvalidArgumentException(sprintf('Missing island property "%s".', $property->name));
            }
        }
    }

    /**
     * Export initialized mount props without requiring pre-mount state to exist.
     *
     * @return array<string, mixed> Declared props, including their PHP defaults.
     */
    public function props(Island $island, Definition $definition): array
    {
        $props = [];
        foreach ($definition->properties as $name => $property) {
            if (!$property->prop) {
                continue;
            }

            /** @var mixed $value */
            $value = $property->read($island);
            Values::validate($value);
            $props[$name] = $value;
        }

        return $props;
    }

    /**
     * Restore signed values and verify that all transported properties can be exported.
     *
     * @param array<string, mixed> $values Verified state from the preceding render.
     */
    public function restore(Island $island, Definition $definition, array $values): void
    {
        $this->assign($island, $definition, $values);
        $this->state->export($island, $definition);
    }
}
