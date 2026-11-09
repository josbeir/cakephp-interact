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

    public const string APPLICATION_PLACEHOLDER = '<!--interact:navigation:application-->';

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
        $bundled = str_contains($html, static::PLACEHOLDER);
        if (!$bundled && !str_contains($html, static::APPLICATION_PLACEHOLDER)) {
            return $response;
        }

        $session = $request->getSession();
        $session->start();

        $policy = DocumentPolicy::fromResponse($response);

        $metadata = [
            'version' => $bundled ? $this->bundledVersion() : '1:application',
            'application' => (string)Configure::read('Interact.navigationVersion', ''),
            'session' => hash_hmac('sha256', $session->id(), Security::getSalt()),
            'policy' => $policy->fingerprint,
            ...($policy->nonces === [] ? [] : ['nonceCount' => count($policy->nonces)]),
        ];
        $marker =
            '<meta name="interact-navigation" content="'
            . htmlspecialchars(json_encode($metadata, JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">';
        foreach ($policy->nonces as $index => $nonce) {
            $marker .=
                '<meta name="interact-navigation-nonce" content="'
                . $index
                . '" nonce="'
                . htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '">';
        }

        $body = new Stream('php://temp', 'wb+');
        $body->write(str_replace([static::PLACEHOLDER, static::APPLICATION_PLACEHOLDER], $marker, $html));

        return $response->withBody($body)->withoutHeader('Content-Length')->withoutHeader('ETag');
    }

    /**
     * Fingerprint the packaged runtime only when the document helper includes that asset.
     */
    protected function bundledVersion(): string
    {
        $bundle = dirname(__DIR__, 2) . '/webroot/js/runtime.js';

        return '1:' . (string)hash_file('sha256', $bundle);
    }
}
