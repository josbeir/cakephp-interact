<?php
declare(strict_types=1);

namespace Interact\Action;

use Closure;
use LogicException;

/**
 * Accepts a single outcome only during an explicitly invoked application action.
 * Hook calls cannot accidentally turn rendering or history restoration into navigation.
 */
class ActionEffects
{
    protected bool $active = false;

    protected ?Outcome $outcome = null;

    /**
     * Run application code within the outcome capability and release it on failure.
     */
    public function capture(Closure $action): ?Outcome
    {
        $this->outcome = null;
        $this->active = true;
        try {
            $action();

            return $this->outcome;
        } finally {
            $this->active = false;
        }
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
