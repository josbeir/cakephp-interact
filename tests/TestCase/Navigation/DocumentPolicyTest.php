<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Navigation;

use Cake\Http\Response;
use Interact\Navigation\DocumentPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Checks nonce normalization without equating different document security capabilities.
 * Enforced and report-only policies share nonce aliases but retain their full policy structure.
 */
class DocumentPolicyTest extends TestCase
{
    public function testNonceRotationKeepsFingerprintAndOrderedAliasesAcrossPolicies(): void
    {
        $response = new Response()->withHeader('Content-Security-Policy', [
            "script-src 'self' 'nonce-script+/=='; style-src-elem 'nonce-style_-='; object-src 'none'",
            "default-src 'nonce-script+/=='; script-src-elem 'nonce-script+/=='",
        ])
            ->withHeader('Content-Security-Policy-Report-Only', "style-src 'nonce-style_-='; report-uri /reports");
        $before = DocumentPolicy::fromResponse($response);
        $this->assertSame(['script+/==', 'style_-='], $before->nonces);
        $rotated = $response->withHeader('Content-Security-Policy', [
            "script-src 'self' 'nonce-freshScript'; style-src-elem 'nonce-freshStyle'; object-src 'none'",
            "default-src 'nonce-freshScript'; script-src-elem 'nonce-freshScript'",
        ])->withHeader('Content-Security-Policy-Report-Only', "style-src 'nonce-freshStyle'; report-uri /reports");
        $this->assertSame($before->fingerprint, DocumentPolicy::fromResponse($rotated)->fingerprint);
        $this->assertSame(['freshScript', 'freshStyle'], DocumentPolicy::fromResponse($rotated)->nonces);
        $merged = $rotated->withHeader('Content-Security-Policy', str_replace(
            'freshStyle',
            'freshScript',
            $rotated->getHeader('Content-Security-Policy'),
        ))
            ->withHeader('Content-Security-Policy-Report-Only', "style-src 'nonce-freshScript'; report-uri /reports");
        $this->assertNotSame($before->fingerprint, DocumentPolicy::fromResponse($merged)->fingerprint);
    }

    public function testAllOtherPolicyBytesAndNonceCapabilitiesRequireFreshDocument(): void
    {
        $response = new Response()->withHeader(
            'Content-Security-Policy',
            "script-src 'nonce-original'; object-src 'none'",
        );
        $before = DocumentPolicy::fromResponse($response);
        foreach ([
            $response->withHeader(
                'Content-Security-Policy',
                "script-src 'nonce-fresh' 'unsafe-eval'; object-src 'none'",
            ),
            $response->withHeader(
                'Content-Security-Policy',
                "script-src 'nonce-fresh' 'nonce-additional'; object-src 'none'",
            ),
            $response->withHeader('Content-Security-Policy', "script-src 'nonce-fresh'; object-src 'self'"),
            $response->withHeader(
                'Content-Security-Policy',
                "script-src 'nonce-fresh'; object-src 'none'; report-uri /changed",
            ),
            $response->withHeader('Content-Security-Policy-Report-Only', "script-src 'nonce-original'"),
            $response->withHeader('Cross-Origin-Opener-Policy', 'same-origin'),
            $response->withHeader('Cross-Origin-Embedder-Policy', 'require-corp'),
            $response->withHeader('Referrer-Policy', 'no-referrer'),
            $response->withHeader('Permissions-Policy', 'camera=()'),
        ] as $changed) {
            $this->assertNotSame($before->fingerprint, DocumentPolicy::fromResponse($changed)->fingerprint);
        }

        $unsupported = new Response()->withHeader(
            'Content-Security-Policy',
            "img-src 'nonce-original'; report-uri /'nonce-original'",
        );
        $this->assertSame([], DocumentPolicy::fromResponse($unsupported)->nonces);
        $this->assertNotSame(
            DocumentPolicy::fromResponse($unsupported)->fingerprint,
            DocumentPolicy::fromResponse($unsupported->withHeader(
                'Content-Security-Policy',
                "img-src 'nonce-fresh'; report-uri /'nonce-fresh'",
            ))->fingerprint,
        );
    }
}
