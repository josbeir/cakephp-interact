<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Discovery\Definition;
use Interact\Island;

/**
 * Carries validated initial props and bound values through the normal child mount lifecycle.
 * The relationship describes ownership; only JSON values are retained in signed identities.
 */
readonly class MountValues
{
    /**
     * Receive fully checked inputs before application construction.
     *
     * @param array<string, mixed> $props Declared mount props.
     * @param array<string, mixed> $bound Initial values for modelable state.
     */
    public function __construct(public array $props, public array $bound, public ?Relation $relation)
    {
    }

    /**
     * Initialize bound state before authorization and mounting.
     */
    public function assign(Island $island, Definition $definition): void
    {
        /** @var mixed $value */
        foreach ($this->bound as $name => $value) {
            $definition->properties[$name]->assign($island, $value);
        }
    }

    /**
     * Add a relationship only when composition is used.
     *
     * @return array{relation?: array{parentId: string, models: array<string, array{path: string, timing: string, delay?: int}>}}
     */
    public function identity(): array
    {
        return $this->relation instanceof Relation ? ['relation' => $this->relation->data()] : [];
    }

    /**
     * Record accepted parent values for later change detection.
     *
     * @return array{relation?: array{parentId: string, models: array<string, array{path: string, timing: string, delay?: int}>}, values?: array<string, mixed>}
     */
    public function child(): array
    {
        return (
            $this->relation instanceof Relation ? ['relation' => $this->relation->data(), 'values' => $this->bound] : []
        );
    }
}
