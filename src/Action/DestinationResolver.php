<?php
declare(strict_types=1);

namespace Interact\Action;

use Cake\Routing\Router;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * Resolves application destinations through CakePHP and checks browser-safe URL boundaries.
 * External redirects are intentional; downloads stay within the current application origin.
 */
class DestinationResolver
{
    /**
     * Keep URL policy replaceable independently of CakePHP route generation.
     */
    public function __construct(protected readonly DestinationPolicy $policy = new DestinationPolicy())
    {
    }

    /**
     * Generate a destination with the mounting page's defaults and current trusted origin.
     */
    public function resolve(Outcome $outcome, RouteContext $routing, UriInterface $origin): string
    {
        $destination = $outcome->destination;
        if (!is_array($destination)) {
            $this->policy->parse((string)$destination);
        }

        $url = Router::url(is_array($destination) ? $routing->complete($destination) : $destination);
        $uri = $this->policy->parse($url);
        if ($outcome->kind === 'download' && !$this->policy->sameOrigin($uri, $origin)) {
            throw new InvalidArgumentException('Download URLs must be same-origin.');
        }

        return $url;
    }
}
