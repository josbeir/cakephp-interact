<?php
declare(strict_types=1);

namespace Interact\Action;

use Cake\Http\UriFactory;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * Checks URL schemes and origins before browser effects enter the response protocol.
 * Redirects may leave the application; download origins include normalized default ports.
 */
class DestinationPolicy
{
    /**
     * Reject unsafe or ambiguous input before Router normalization can disguise it as a path.
     */
    public function parse(string $url): UriInterface
    {
        if ($url === '' || preg_match('/[\\x00-\\x20\\x7f\\\\]/', $url)) {
            throw new InvalidArgumentException('Invalid browser destination.');
        }

        $uri = new UriFactory()->createUri($url);
        $scheme = strtolower($uri->getScheme());
        if (
            (
                $scheme !== ''
                && (
                    !in_array($scheme, ['http', 'https'], true)
                    || $uri->getHost() === ''
                )
            )
            || $uri->getUserInfo() !== ''
        ) {
            throw new InvalidArgumentException('Destinations must use HTTP(S) without credentials.');
        }

        return $uri;
    }

    /**
     * Compare normalized authorities while allowing application-relative destinations.
     */
    public function sameOrigin(UriInterface $uri, UriInterface $origin): bool
    {
        if ($uri->getHost() === '') {
            return $uri->getScheme() === '';
        }

        $scheme = $uri->getScheme() ?: $origin->getScheme();
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $originPort = $origin->getPort() ?? ($origin->getScheme() === 'https' ? 443 : 80);

        return (
            strtolower($uri->getHost()) === strtolower($origin->getHost())
                && $scheme === $origin->getScheme()
                && $port === $originPort
        );
    }
}
