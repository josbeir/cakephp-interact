<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\State;

use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Dom\HTMLDocument;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\State\InitialErrorsIsland;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies initial feedback reaches browser metadata without changing loading or error cleanup.
 * Real snapshots and native attribute decoding preserve escaped application messages.
 */
class InitialErrorsTest extends TestCase
{
    /**
     * Exercise each public loading mode through its normal initialization path.
     *
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        foreach (['eager', 'lazy', 'defer', 'manual'] as $mode) {
            yield $mode => [$mode];
        }
    }

    /**
     * Export mount feedback only once content exists, then remove stale metadata on success.
     */
    #[DataProvider('modes')]
    public function testInitialFeedbackSurvivesAttributeEncoding(string $mode): void
    {
        $registry = new Registry();
        $registry->register(InitialErrorsIsland::class);

        $request = new ServerRequest(['session' => new Session([
            'defaults' => 'php',
            'ini' => ['session.use_cookies' => 0],
        ])]);
        $context = new IslandContext($request, 'default', '/_interact/update');
        $runtime = new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('e', 32)),
            new CallbackRenderer(
                static fn(): string => '<p>Content</p>',
            ),
        );
        $result = $runtime->mount('InitialErrors', [], null, $context, new Options($mode));
        if ($mode !== 'eager') {
            $this->assertStringNotContainsString('data-interact-errors', $result->html);
            $result = $runtime->update(['snapshot' => $result->snapshot, 'initialize' => true], $context);
        }

        $this->assertSame(InitialErrorsIsland::ERRORS, $result->errors);
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $result->html . '</body></html>');
        $encoded = $document->querySelector('[data-interact-id]')?->getAttribute('data-interact-errors');
        $this->assertIsString($encoded);
        $this->assertSame(InitialErrorsIsland::ERRORS, json_decode($encoded, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('<value>', $result->html);
        $cleared = $runtime->update(['snapshot' => $result->snapshot, 'action' => 'clear'], $context);
        $this->assertSame([], $cleared->errors);
        $this->assertStringNotContainsString('data-interact-errors', $cleared->html);
    }
}
