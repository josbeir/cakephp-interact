<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Discovery\Definition;
use Interact\Event\BrowserEvent;
use Interact\Island;
use InvalidArgumentException;

/**
 * Binds an untrusted browser event to an explicitly listening action signature.
 *
 * PHP defaults stay server-side; normalized arguments still undergo the ordinary
 * action type checks and authorization before application code executes.
 * Subclasses retain the constructor signature used by read().
 *
 * @consistent-constructor
 */
readonly class EventInvocation
{
    public BrowserEvent $event;

    /**
     * Require one named detail record instead of a positional action tuple.
     *
     * @param list<mixed> $arguments Browser-provided event arguments.
     * @throws InvalidArgumentException When the envelope or named JSON payload is invalid.
     */
    public function __construct(string $name, array $arguments)
    {
        if (count($arguments) !== 1 || !is_array($arguments[0])) {
            throw new InvalidArgumentException('Invalid island event invocation.');
        }

        $this->event = new BrowserEvent($name, $arguments[0]);
    }

    /**
     * Read the optional event marker only for an actual action request.
     *
     * @param list<mixed> $arguments Validated positional envelope.
     * @throws InvalidArgumentException When a nonnull marker cannot select an action event.
     */
    public static function read(mixed $name, ?string $action, array $arguments): ?static
    {
        if ($name === null) {
            return null;
        }

        if (!is_string($name) || $action === null) {
            throw new InvalidArgumentException('Invalid island event marker.');
        }

        return new static($name, $arguments);
    }

    /**
     * Match names to the declared PHP parameters and preserve omitted defaults.
     *
     * @param array<string, mixed>|Island $source Accepted snapshot props or the restored island.
     * @return list<mixed> Arguments ready for ordinary type and count validation.
     * @throws InvalidArgumentException When the action does not listen or required fields are missing.
     */
    public function arguments(Definition $definition, ?string $action, array|Island $source = []): array
    {
        if (!in_array($action, $definition->listeners->resolve($source)[$this->event->name] ?? [], true)) {
            throw new InvalidArgumentException('The action does not listen for this event.');
        }

        $values = [];
        foreach ($definition->actions[$action]->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $this->event->detail)) {
                $values[] = $this->event->detail[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $values[] = $parameter->getDefaultValue();
            } else {
                throw new InvalidArgumentException('Missing island event argument.');
            }
        }

        return $values;
    }

    /**
     * Resolve the action tuple without changing ordinary positional browser calls.
     *
     * @return list<mixed> Server-bound event arguments or the original action tuple.
     */
    public static function resolve(Definition $definition, UpdateRequest $request, Island $island): array
    {
        return $request->event?->arguments($definition, $request->action, $island) ?? $request->arguments;
    }
}
