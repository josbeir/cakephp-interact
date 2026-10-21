<?php
declare(strict_types=1);

namespace Interact\Action;

use Closure;
use Interact\Island;
use Interact\IslandContext;
use Interact\Runtime\RedirectResult;
use Interact\Runtime\Result;
use Interact\Runtime\SnapshotData;

/**
 * Selects terminal navigation or an ordinary render with a native download request.
 * Validation suppresses browser outcomes, while redirects never evaluate view data.
 */
class OutcomeResponder
{
    /**
     * Receive replaceable CakePHP destination resolution at the response boundary.
     */
    public function __construct(protected readonly DestinationResolver $destinations = new DestinationResolver())
    {
    }

    /**
     * Resolve effects before rendering and emit them only for an accepted action.
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
        if (!$outcome instanceof Outcome || $island->errors() !== []) {
            return $render();
        }

        $destination = $this->destinations->resolve($outcome, $identity->routing, $context->request->getUri());
        if ($outcome->kind === 'redirect') {
            return new RedirectResult($identity->id, $destination, $outcome->replace);
        }

        $result = $render();
        if ($result->errors !== []) {
            return $result;
        }

        return new Result($result->id, $result->html, $result->snapshot, [
            'download' => $destination,
            'state' => $result->state,
            'url' => $result->url,
            'uploads' => $result->uploads,
            'writable' => $result->writable,
            'errors' => $result->errors,
            'events' => $result->events,
        ]);
    }
}
