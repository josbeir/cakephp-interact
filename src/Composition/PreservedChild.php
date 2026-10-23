<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Discovery\Declaration;
use Interact\Discovery\Definition;
use Interact\Discovery\Property;
use Interact\Runtime\RenderFrame;
use Interact\Runtime\Result;
use InvalidArgumentException;
use LogicException;

/**
 * Preserves a keyed child while emitting verified updates for its parent-owned inputs.
 * Drafts and child snapshots remain in the browser until that child's own request runs.
 */
class PreservedChild
{
    /**
     * Receive request signing without requiring it for ordinary standalone preservation.
     */
    public function __construct(protected readonly ?InputTickets $inputs)
    {
    }

    /**
     * Check immutable inputs and produce a preservation marker with an optional capability.
     *
     * @param array<string, mixed> $props New application-owned props.
     * @param array{id: string, alias: string, props: array<string, mixed>, urlPrefix?: string, relation?: array{parentId: string, models: array<string, array{path: string, timing: string, delay?: int}>}, values?: array<string, mixed>} $previous Previous signed child identity.
     * @return array{child: array{id: string, alias: string, props: array<string, mixed>, urlPrefix?: string, relation?: array{parentId: string, models: array<string, array{path: string, timing: string, delay?: int}>}, values?: array<string, mixed>}, result: Result}
     */
    public function render(
        Declaration|Definition $declaration,
        array $props,
        array $previous,
        RenderFrame $parent,
        ?Relation $relation,
    ): array {
        $definition = $declaration instanceof Definition ? $declaration : null;
        $rendering = $declaration instanceof Definition ? $declaration->declaration : $declaration;
        $reactive = $definition instanceof Definition
            ? array_filter($definition->properties, static fn(Property $field): bool => $field->reactive)
            : [];
        if (
            $previous['alias'] !== $rendering->name
            || array_diff_key($previous['props'], $reactive) !== array_diff_key($props, $reactive)
            || ($previous['relation'] ?? null) !== $relation?->data()
        ) {
            throw new InvalidArgumentException('Changing a child alias, props, or model bindings requires a new key.');
        }

        $extra = '';
        if ($relation instanceof Relation) {
            $values = new BoundValues()->values(
                $relation,
                $definition ?? throw new LogicException('Bindings require compiled declarations.'),
                $parent,
            );
            if ($previous['props'] !== $props || ($previous['values'] ?? []) !== $values) {
                $inputs = $this->inputs ?? throw new LogicException('Parent input tickets require a signer.');
                $ticket = $inputs->issue($previous, array_intersect_key($props, $reactive), $values, $parent);
                $extra = ' data-interact-parent-inputs="' . htmlspecialchars($ticket, ENT_QUOTES, 'UTF-8') . '"';
            }

            $previous['values'] = $values;
        }

        $previous['props'] = $props;
        $result = new Result(
            $previous['id'],
            '<'
                . $rendering->tag
                . ' data-interact-preserve="'
                . htmlspecialchars($previous['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"'
                . $extra
                . '></'
                . $rendering->tag
                . '>',
            '',
        );

        return ['child' => $previous, 'result' => $result];
    }
}
