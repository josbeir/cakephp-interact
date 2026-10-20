<?php
declare(strict_types=1);

namespace Interact\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use Interact\Http\UpdateHandler;

/**
 * Processes versioned AJAX updates after the application's normal middleware stack.
 *
 * The factory creates a fresh rendering runtime for the verified island snapshot.
 * Validation returns renderable feedback; malformed state and expected authorization
 * failures return uncached JSON without rerunning the original page controller.
 */
class UpdateController extends Controller
{
    /**
     * Return a versioned JSON result; never mix login/error documents into island markup.
     */
    public function update(UpdateHandler $handler): Response
    {
        return $handler->handle($this->getRequest(), $this->getResponse());
    }
}
