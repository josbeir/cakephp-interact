<?php
declare(strict_types=1);

namespace Interact\Navigation;

use Psr\Http\Message\ResponseInterface;

/**
 * Fingerprints document security policies while identifying replaceable element nonces.
 *
 * Only nonce values in script/style source directives are normalized. Their alias
 * relationships and all other policy bytes remain part of compatibility checks.
 *
 * @consistent-constructor
 */
readonly class DocumentPolicy
{
    /**
     * Retain the opaque policy fingerprint and ordered, deduplicated nonce values.
     *
     * @param list<string> $nonces Values are exposed only through nonce-hiding attributes.
     */
    public function __construct(public string $fingerprint, public array $nonces) {}

    /**
     * Capture final middleware headers without weakening the browser's original policy.
     */
    public static function fromResponse(ResponseInterface $response): static
    {
        $policy = [];
        $nonces = [];
        $aliases = [];
        foreach ([
            'Content-Security-Policy',
            'Content-Security-Policy-Report-Only',
            'Cross-Origin-Opener-Policy',
            'Cross-Origin-Embedder-Policy',
            'Referrer-Policy',
            'Permissions-Policy',
        ] as $header) {
            $values = $response->getHeader($header);
            if (str_starts_with($header, 'Content-Security-Policy')) {
                foreach ($values as $index => $value) {
                    $values[$index] = static::normalize($value, $aliases, $nonces);
                }
            }

            $policy[$header] = $values;
        }

        return new static(hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)), $nonces);
    }

    /**
     * Normalize nonce source expressions only within element-governing CSP directives.
     *
     * @param array<string, int> $aliases Nonce aliases shared across enforced and reporting policies.
     * @param list<string> $nonces Ordered values for hidden document markers.
     */
    protected static function normalize(string $policy, array &$aliases, array &$nonces): string
    {
        return (string)preg_replace_callback(
            '/(?:^|[;,])\s*(?:default-src|script-src(?:-elem)?|style-src(?:-elem)?)\s+[^;,]*/i',
            static function (array $directive) use (&$aliases, &$nonces): string {
                return (string)preg_replace_callback(
                    "/'nonce-([A-Za-z0-9+\/_-]+={0,2})'/i",
                    static function (array $source) use (&$aliases, &$nonces): string {
                        $nonce = $source[1];
                        $key = 'nonce:' . $nonce;
                        if (!isset($aliases[$key])) {
                            $aliases[$key] = count($nonces);
                            $nonces[] = $nonce;
                        }

                        return "'nonce-@" . $aliases[$key] . "'";
                    },
                    $directive[0],
                );
            },
            $policy,
        );
    }
}
