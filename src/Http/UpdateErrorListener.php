<?php
declare(strict_types=1);

namespace Interact\Http;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Http\ServerRequest;
use Interact\Debugging\Diagnostics;
use Throwable;

/**
 * Formats failures from the update route after CakePHP has logged the exception.
 *
 * Routing parameters identify custom update scopes too. Other routes and responses
 * already supplied by application listeners retain their existing error handling.
 */
class UpdateErrorListener implements EventListenerInterface
{
    /**
     * Share the controller's failure contract through an injectable responder.
     */
    public function __construct(
        protected readonly FailureResponder $failures,
        protected readonly Diagnostics $diagnostics = new Diagnostics(),
    ) {
    }

    /**
     * Subscribe at the normal CakePHP exception rendering boundary.
     *
     * @return array<string, string>
     */
    public function implementedEvents(): array
    {
        return ['Exception.beforeRender' => 'render'];
    }

    /**
     * Replace only unhandled island update exceptions with safe JSON feedback.
     */
    public function render(EventInterface $event): void
    {
        /** @var mixed $request */
        $request = $event->getData('request');
        /** @var mixed $exception */
        $exception = $event->getData('exception');
        if (
            $event->getResult() !== null
            || !$request instanceof ServerRequest
            || !$exception instanceof Throwable
            || !is_string($request->getParam('interactScope'))
        ) {
            return;
        }

        $event->setResult($this->diagnostics->failure($exception, $this->failures->fromException($exception)));
    }
}
