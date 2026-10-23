<?php
declare(strict_types=1);

namespace Interact\Action;

use Closure;
use Interact\Streaming\Stream;
use LogicException;

/**
 * Accepts a single outcome only during an explicitly invoked application action.
 * Hook calls cannot accidentally turn rendering or history restoration into navigation.
 */
class ActionEffects
{
    protected ?Stream $stream = null;

    protected bool $active = false;

    protected bool $skipRender = false;

    protected ?Outcome $outcome = null;

    /**
     * Run application code within the outcome capability and release it on failure.
     */
    public function capture(Closure $action, ?Stream $stream = null): ?Outcome
    {
        $this->outcome = null;
        $this->skipRender = false;
        $this->active = true;
        $this->stream = $stream;
        try {
            $action();

            return $this->outcome;
        } finally {
            $this->active = false;
            $this->stream = null;
        }
    }

    /**
     * Require the active streaming action capability, excluding hooks and ordinary actions.
     */
    public function stream(): Stream
    {
        return (
            $this->stream ?? throw new LogicException('Streaming requires an active action declared with stream: true.')
        );
    }

    /**
     * Suppress template rendering for this action, without suppressing validation feedback.
     */
    public function skipRender(): void
    {
        if (!$this->active) {
            throw new LogicException('Rendering may only be skipped inside declared actions.');
        }

        $this->skipRender = true;
    }

    /**
     * Read the rendering choice after action capture has completed.
     */
    public function skipsRender(): bool
    {
        return $this->skipRender;
    }

    /**
     * Reject hook usage and ambiguous combinations rather than silently discarding an outcome.
     */
    public function record(Outcome $outcome): void
    {
        if (!$this->active) {
            throw new LogicException('Redirects and downloads may only be requested inside declared actions.');
        }

        if ($this->outcome instanceof Outcome) {
            throw new LogicException('An action may request only one redirect or download.');
        }

        $this->outcome = $outcome;
    }
}
