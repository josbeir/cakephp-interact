<?php
declare(strict_types=1);

namespace Interact\Loading;

use Interact\Composition\ParentValues;
use Interact\Discovery\Definition;
use Interact\Island;
use Interact\Runtime\SnapshotData;
use Interact\Runtime\Values;
use InvalidArgumentException;

/**
 * Separates a signed initialization capability from an ordinary mounted snapshot.
 *
 * Only declared props cross this boundary; state and request-local services are created
 * by the authorized mount lifecycle when the browser explicitly initializes the island.
 */
readonly class PendingMount
{
    public SnapshotData $identity;

    /**
     * @var array<string, mixed> Verified mount parameters, including default props.
     */
    public array $props;

    /**
     * @var array<string, mixed> Server-supplied initial bound values, separate from mount-only props.
     */
    public array $bound;

    /**
     * Apply signed initial values and current verified parent inputs before mounting.
     */
    public function apply(Island $island, Definition $definition, ParentValues $parent): void
    {
        /** @var mixed $value */
        foreach (array_replace($this->bound, $parent->models, $parent->props) as $name => $value) {
            $definition->properties[$name]->assign($island, $value);
        }
    }

    /**
     * Validate a verified pending ticket before constructing application code.
     *
     * @param array<string, mixed> $data Signed but not yet schema-checked ticket data.
     */
    public function __construct(array $data)
    {
        if (($data['pending'] ?? null) !== true) {
            throw new InvalidArgumentException('Expected a pending island mount.');
        }

        $this->props = Values::map($data['props'] ?? null);
        $this->bound = Values::map($data['bound'] ?? []);
        Values::validate($this->props);
        unset($data['pending'], $data['props'], $data['bound']);
        $this->identity = new SnapshotData($data);
        if ($this->identity->state !== [] || $this->identity->children !== [] || $this->identity->uploads !== []) {
            throw new InvalidArgumentException('Pending mounts cannot contain state, children, or uploads.');
        }
    }
}
