<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Discovery\Definition;
use Interact\IslandContext;
use Interact\Runtime\RenderFrame;
use Interact\Runtime\SnapshotData;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotCodec;
use InvalidArgumentException;
use LogicException;

/**
 * Issues and verifies narrowly scoped parent-input capabilities for an existing child.
 *
 * Tickets authorize transport of declared inputs, not access to application resources.
 * Their purpose and target are checked before island construction; ordinary snapshots
 * cannot be substituted for them or used to write mount-only props.
 */
class InputTickets
{
    /**
     * Reuse snapshot signing, expiry, session binding, and middleware-scope verification.
     */
    public function __construct(protected readonly SnapshotCodec $snapshots) {}

    /**
     * Read the untrusted optional ticket without accepting another transport type.
     *
     * @param array<string, mixed> $request Browser envelope.
     */
    public static function token(array $request): ?string
    {
        /** @var mixed $token */
        $token = $request['parentInputs'] ?? null;
        if ($token !== null && !is_string($token)) {
            throw new InvalidArgumentException('Parent inputs require a signed ticket.');
        }

        return $token;
    }

    /**
     * Sign the new inputs after the authorized parent has rendered a preserved child.
     *
     * @param array<string, mixed> $child Signed previous child identity and immutable relationship.
     * @param array<string, mixed> $props New reactive values only.
     * @param array<string, mixed> $models Accepted parent model values.
     */
    public function issue(array $child, array $props, array $models, RenderFrame $parent): string
    {
        $context = $parent->context ?? throw new LogicException('Parent input tickets require a render context.');

        return $this->snapshots->seal(
            [
                'kind' => 'parent-inputs',
                'id' => $child['id'],
                'alias' => $child['alias'],
                'relation' => $child['relation'],
                'props' => $props,
                'models' => $models,
            ],
            $context->binding(),
            $context->scope,
        );
    }

    /**
     * Verify a parent capability and every target field before constructing application code.
     */
    public function read(
        ?string $ticket,
        SnapshotData $identity,
        Definition $definition,
        IslandContext $context,
    ): ParentValues {
        if ($ticket === null) {
            return new ParentValues();
        }

        $data = $this->snapshots->open($ticket, $context->binding(), $context->scope);
        if (
            ($data['kind'] ?? null) !== 'parent-inputs'
            || ($data['id'] ?? null) !== $identity->id
            || ($data['alias'] ?? null) !== $identity->alias
            || !$identity->relation instanceof Relation
            || ($data['relation'] ?? null) !== $identity->relation->data()
        ) {
            throw new InvalidSnapshotException('Parent inputs do not belong to this child.');
        }

        return new InputFields()->read($data, $identity, $definition);
    }
}
