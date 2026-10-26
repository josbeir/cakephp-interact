<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Runtime\Values;
use InvalidArgumentException;

/**
 * Describes a child's immutable direct-parent relationship and explicit value bindings.
 *
 * This declaration travels in signed identities. Browser metadata coordinates drafts;
 * writable declarations and current authorization still govern every server update.
 *
 * @consistent-constructor
 */
readonly class Relation
{
    /**
     * @var array<string, array{path: string, timing: string, delay?: int}> Named child value bindings.
     */
    public array $models;

    /**
     * Validate application configuration and signed relationship metadata through one contract.
     *
     * @param array<string, mixed> $models Child property names mapped to paths or timing options.
     */
    public function __construct(public string $parentId, array $models = [])
    {
        if ($parentId === '') {
            throw new InvalidArgumentException('A child relationship requires a parent identity.');
        }

        $bindings = [];
        /** @var mixed $value */
        foreach (Values::map($models) as $name => $value) {
            if (count(Values::path($name)) !== 1) {
                throw new InvalidArgumentException('Bind a top-level modelable child property.');
            }

            $bindings[$name] = new ModelOptions()->read($value);
        }

        $this->models = $bindings;
    }

    /**
     * Read an optional signed relationship without accepting malformed parent identities.
     */
    public static function read(mixed $value): ?static
    {
        if ($value === null) {
            return null;
        }

        $data = Values::map($value);
        if (!is_string($data['parentId'] ?? null)) {
            throw new InvalidArgumentException('Invalid signed parent relationship.');
        }

        return new static($data['parentId'], Values::map($data['models'] ?? null));
    }

    /**
     * Export the immutable server relationship for signing and ticket comparisons.
     *
     * @return array{parentId: string, models: array<string, array{path: string, timing: string, delay?: int}>}
     */
    public function data(): array
    {
        return ['parentId' => $this->parentId, 'models' => $this->models];
    }

    /**
     * Preserve an empty binding map as a JSON object for browser relationship validation.
     *
     * @return array{parentId: string, models: object} Browser-only serialization shape.
     */
    public function browserData(): array
    {
        $data = $this->data();

        return [...$data, 'models' => (object)$data['models']];
    }
}
