<?php
declare(strict_types=1);

namespace Interact\Authorization;

use Authorization\AuthorizationServiceInterface;
use Cake\Http\ServerRequest;

/**
 * Preserves protocol rejection responses under CakePHP's optional Authorization middleware.
 *
 * Call only after rejecting the request before any island can be used.
 *
 * @internal
 */
class RejectedRequest
{
    /**
     * Acknowledge that there is no island policy for the middleware to track on this request.
     */
    public static function acknowledge(ServerRequest $request): void
    {
        /** @var mixed $authorization */
        $authorization = $request->getAttribute('authorization');
        if ($authorization instanceof AuthorizationServiceInterface && !$authorization->authorizationChecked()) {
            $authorization->skipAuthorization();
        }
    }
}
