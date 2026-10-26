<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Closure;
use Interact\Action\Outcome;
use Interact\Action\OutcomeResponder;
use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Streaming\Stream;
use Interact\UpdatedPaths;
use InvalidArgumentException;
use ReflectionNamedType;

/**
 * Validates explicitly exposed action signatures before invoking application behavior.
 */
class ActionInvoker
{
    /**
     * Keep browser outcome handling separate from action signature validation.
     */
    public function __construct(protected readonly OutcomeResponder $outcomes = new OutcomeResponder())
    {
    }

    /**
     * Select an action response without making the runtime own browser effect details.
     *
     * @param Closure(): Result $render Normal authorized island rendering.
     */
    public function respond(
        Island $island,
        ?Outcome $outcome,
        SnapshotData $identity,
        IslandContext $context,
        Closure $render,
    ): Result {
        return $this->outcomes->respond($island, $outcome, $identity, $context, $render);
    }

    /**
     * Reject unknown actions, unsupported arguments, and invalid argument counts before application
     * execution.
     *
     * @param list<mixed> $arguments Reject invalid signatures before constructing or authorizing an island.
     */
    public function validate(
        Definition $definition,
        ?string $action,
        array $arguments,
        ?EventInvocation $event = null,
    ): void {
        $arguments = $event?->arguments($definition, $action) ?? $arguments;
        if ($action === null) {
            if ($arguments !== []) {
                throw new InvalidArgumentException('Synchronization cannot have action arguments.');
            }

            return;
        }

        $method = $definition->actions[$action] ?? throw new InvalidArgumentException('Unknown island action.');
        $parameters = $method->getParameters();
        if (count($arguments) < $method->getNumberOfRequiredParameters() || count($arguments) > count($parameters)) {
            throw new InvalidArgumentException('Invalid island action argument count.');
        }

        /** @var mixed $value */
        foreach ($arguments as $index => $value) {
            Values::validate($value);
            $type = $parameters[$index]->getType();
            $matches =
                $type instanceof ReflectionNamedType
                && match ($type->getName()) {
                    'string' => is_string($value),
                    'int' => is_int($value),
                    'float' => is_int($value) || is_float($value),
                    'bool' => is_bool($value),
                    'array' => is_array($value),
                    default => false,
                };
            if (!$matches && !($value === null && $type instanceof ReflectionNamedType && $type->allowsNull())) {
                throw new InvalidArgumentException('Invalid island action argument type.');
            }
        }
    }

    /**
     * Invoke hooks and the action only after state validation and authorization have succeeded.
     *
     * @param list<string> $paths Accepted state changes, applied before entering the action capability.
     */
    public function invoke(
        Definition $definition,
        Island $island,
        UpdateRequest $request,
        ?Stream $stream = null,
        array $paths = [],
    ): ?Outcome {
        if ($paths !== []) {
            $island->updated(new UpdatedPaths($paths));
        }

        if ($request->action !== null) {
            $callback = $definition->actions[$request->action]->getClosure($island);
            $arguments = EventInvocation::resolve($definition, $request);

            return $island->actionEffects()->capture(static fn() => $callback(...$arguments), $stream);
        }

        return null;
    }
}
