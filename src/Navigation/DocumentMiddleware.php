<?php
declare(strict_types=1);

namespace Interact\Navigation;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Utility\Security;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Marks complete Interact documents at the response boundary, after application middleware.
 *
 * Navigation keeps the original document's browser policy. Opaque session and policy
 * fingerprints force native visits when a fetched page cannot share that document.
 */
class DocumentMiddleware implements MiddlewareInterface
{
    public const string PLACEHOLDER = '<!--interact:navigation-->';

    /**
     * Replace only the helper's document marker; fragments and streamed responses stay untouched.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if (
            !$request instanceof ServerRequest
            || $request->getMethod() !== 'GET'
            || $response->getStatusCode() !== 200
            || !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html')
            || $response->hasHeader('Content-Encoding')
            || $response->hasHeader('Content-Disposition')
            || !$response->getBody()->isSeekable()
        ) {
            return $response;
        }

        $html = (string)$response->getBody();
        if (!str_contains($html, static::PLACEHOLDER)) {
            return $response;
        }

        $session = $request->getSession();
        $session->start();

        $policy = [];
        foreach ([
            'Content-Security-Policy',
            'Content-Security-Policy-Report-Only',
            'Cross-Origin-Opener-Policy',
            'Cross-Origin-Embedder-Policy',
            'Referrer-Policy',
            'Permissions-Policy',
        ] as $header) {
            $policy[$header] = $response->getHeader($header);
        }

        $bundle = dirname(__DIR__, 2) . '/webroot/js/runtime.js';
        $metadata = [
            'version' => '1:' . (is_file($bundle) ? (string)hash_file('sha256', $bundle) : 'source'),
            'application' => (string)Configure::read('Interact.navigationVersion', ''),
            'session' => hash_hmac('sha256', $session->id(), Security::getSalt()),
            'policy' => hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)),
        ];
        $marker =
            '<meta name="interact-navigation" content="'
            . htmlspecialchars(json_encode($metadata, JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">';
        $body = new Stream('php://temp', 'wb+');
        $body->write(str_replace(static::PLACEHOLDER, $marker, $html));

        return $response->withBody($body)->withoutHeader('Content-Length')->withoutHeader('ETag');
    }
}
