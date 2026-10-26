<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Events;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\Event\ListenerNames;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Events\ScopedListenerIsland;
use Interact\Test\Fixture\Events\ScopedParentIsland;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\Runtime\RuntimeReference;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies cached placeholder declarations against independent accepted record identities.
 * Matching names remain routing filters; normal signatures and authorization still apply.
 */
class DynamicEventNamesTest extends TestCase
{
    protected function context(): IslandContext
    {
        return new IslandContext(new ServerRequest(), 'events', '/_interact/update');
    }

    protected function runtime(): Runtime
    {
        $registry = new Registry();
        $registry->register(ScopedListenerIsland::class, 'Scoped');
        $registry->register(ScopedParentIsland::class, 'ScopedParent');

        $reference = new RuntimeReference();

        $runtime = new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('d', 32)),
            new CallbackRenderer(fn($definition, $values): string => $definition->class === ScopedParentIsland::class
                ? $reference->runtime->mount(
                    'Scoped',
                    ['articleId' => $values['articleId']],
                    'detail',
                    $this->context(),
                )->html
                : '<p>Scoped events</p>'),
        );
        $reference->runtime = $runtime;

        return $runtime;
    }

    public function testCachedDeclarationsResolveIndependentMountsAndLiteralCollisions(): void
    {
        $definition = new Definition(ScopedListenerIsland::class);
        $resolver = new ListenerNames(['scope:{scope}:{articleId}:{articleId}' => [
            'articleSaved',
        ]], $definition->properties);
        static::assertSame(
            ['scope:public:0:0' => ['articleSaved']],
            $resolver->resolve(['scope' => 'public', 'articleId' => 0]),
        );
        static::assertSame(['scope::2:2' => ['articleSaved']], $resolver->resolve(['scope' => '', 'articleId' => 2]));
        static::assertSame(['scope:public:1:1' => ['articleSaved']], $resolver->resolve(new ScopedListenerIsland()));
        $runtime = $this->runtime();
        $first = $runtime->mount('Scoped', ['articleId' => 1], 'first', $this->context());
        $second = $runtime->mount('Scoped', ['articleId' => 2], 'second', $this->context());
        static::assertSame(['articleSaved', 'firstArticle'], $first->listeners['article-saved:1']);
        static::assertSame(['articleSaved'], $second->listeners['article-saved:2']);
        static::assertSame(['firstArticle'], $second->listeners['article-saved:1']);
        static::assertArrayNotHasKey('article-saved:2', $first->listeners);
        static::assertEquals((object)$second->listeners, $second->payload()['listeners']);
    }

    /**
     * Reject malformed templates before resolving any mounted props.
     *
     * @param string $template Invalid placeholder syntax or declaration ownership.
     */
    #[DataProvider('invalidTemplates')]
    public function testInvalidTemplatesFailDuringCompilation(string $template): void
    {
        $definition = new Definition(ScopedListenerIsland::class);
        $this->expectException(InvalidArgumentException::class);
        new ListenerNames([$template => ['articleSaved']], $definition->properties);
    }

    /**
     * Invalid names never become browser listener metadata.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidTemplates(): iterable
    {
        yield 'missing property' => ['saved:{missing}'];
        yield 'undeclared property' => ['saved:{undeclared}'];
        yield 'state is not a prop' => ['saved:{stateKey}'];
        yield 'array prop' => ['saved:{unsupported}'];
        yield 'boolean prop' => ['saved:{enabled}'];
        yield 'nested path' => ['saved:{article.id}'];
        yield 'method call' => ['saved:{articleId()}'];
        yield 'empty placeholder' => ['saved:{}'];
        yield 'unclosed placeholder' => ['saved:{articleId'];
        yield 'nested braces' => ['saved:{{articleId}}'];
        yield 'wildcard' => ['saved:*'];
        yield 'uppercase prefix' => ['Saved:{articleId}'];
        yield 'dot separator' => ['saved.{articleId}'];
    }

    /**
     * Reject unavailable or invalid values without silently widening event delivery.
     *
     * @param array<string, mixed> $values Missing or invalid runtime prop values.
     */
    #[DataProvider('invalidValues')]
    public function testInvalidConcreteNamesFailWithoutCoercingValues(array $values): void
    {
        $resolver = new ListenerNames(['saved:{scope}' => [
            'articleSaved',
        ]], new Definition(ScopedListenerIsland::class)->properties);
        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve($values);
    }

    /**
     * Keep absent/null values distinct from supported zero and empty strings.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidValues(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['scope' => null]];
        yield 'boolean' => [['scope' => false]];
        yield 'float' => [['scope' => 1.2]];
        yield 'array' => [['scope' => []]];
        yield 'uppercase' => [['scope' => 'Private']];
        yield 'space' => [['scope' => 'private group']];
        yield 'newline' => [['scope' => "private\n"]];
        yield 'braces' => [['scope' => '{other}']];
    }

    public function testRenderedAndRenderlessResponsesUseTheAcceptedScope(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Scoped', [], null, $context);
        $changed = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'selectArticle',
            'args' => [2],
        ], $context);
        static::assertNull($changed->payload()['html']);
        static::assertSame(['articleSaved'], $changed->listeners['article-saved:2']);
        $accepted = $runtime->update([
            'snapshot' => $changed->snapshot,
            'action' => 'articleSaved',
            'event' => 'article-saved:2',
            'args' => [[]],
            'updates' => ['draft' => 'keep'],
        ], $context);
        static::assertSame(['articleId' => 2], $accepted->state['received']);
        static::assertSame('keep', $accepted->state['draft']);
        static::assertStringContainsString('&quot;article-saved:2&quot;', $accepted->html);
        $this->expectException(InvalidArgumentException::class);
        $runtime->update([
            'snapshot' => $accepted->snapshot,
            'action' => 'articleSaved',
            'event' => 'article-saved:1',
            'args' => [[]],
        ], $context);
    }

    public function testPropValuesCannotIntroduceAdditionalPlaceholderExpansion(): void
    {
        $resolver = new ListenerNames(
            ['saved:{scope}:{articleId}' => ['articleSaved']],
            new Definition(ScopedListenerIsland::class)->properties,
        );
        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve(['scope' => '{articleId}', 'articleId' => 1]);
    }

    public function testDynamicListenersRetainCurrentAuthorization(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Scoped', [], null, $context);
        $this->expectException(ForbiddenException::class);
        $runtime->update(
            [
                'snapshot' => $mount->snapshot,
                'action' => 'articleSaved',
                'event' => 'article-saved:1',
                'args' => [[]],
            ],
            new IslandContext($context->request->withAttribute('deny', true), $context->scope, $context->endpoint),
        );
    }

    public function testUntrustedUpdatesCannotChooseTheListenerProp(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Scoped', [], null, $context);
        $this->expectException(InvalidArgumentException::class);
        $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'articleSaved',
            'event' => 'article-saved:1',
            'args' => [[]],
            'updates' => ['articleId' => 2],
        ], $context);
    }

    public function testPendingPlaceholdersDoNotExposeAnUnresolvedTemplate(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $pending = $runtime->mount('Scoped', ['articleId' => 2], null, $context, new Options('manual'));
        static::assertSame([], $pending->listeners);
        static::assertStringNotContainsString('data-interact-listeners', $pending->html);
        $loaded = $runtime->update(['snapshot' => $pending->snapshot, 'initialize' => true], $context);
        static::assertSame(['articleSaved'], $loaded->listeners['article-saved:2']);
        static::assertStringNotContainsString('{articleId}', $loaded->html);
    }

    #[DataProvider('parentEvents')]
    public function testVerifiedParentChangesSelectTheNewEventScope(string $event): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $parent = $runtime->mount('ScopedParent', [], null, $context);
        $snapshots = [];
        static::assertSame(2, preg_match_all('/data-interact-snapshot="([^"]+)"/', $parent->html, $snapshots));
        $changed = $runtime->update(['snapshot' => $parent->snapshot, 'updates' => ['articleId' => 2]], $context);
        $ticket = [];
        static::assertSame(1, preg_match('/data-interact-parent-inputs="([^"]+)"/', $changed->html, $ticket));
        if ($event === 'article-saved:1') {
            $this->expectException(InvalidArgumentException::class);
        }

        $result = $runtime->update([
            'snapshot' => $snapshots[1][1],
            'parentInputs' => html_entity_decode($ticket[1], ENT_QUOTES),
            'action' => 'articleSaved',
            'event' => $event,
            'args' => [[]],
        ], $context);
        static::assertSame(['articleId' => 2], $result->state['received']);
        static::assertSame(['articleSaved'], $result->listeners['article-saved:2']);
    }

    /**
     * Events must match the current verified parent identity rather than the older snapshot.
     *
     * @return iterable<string, array{string}>
     */
    public static function parentEvents(): iterable
    {
        yield 'current identity' => ['article-saved:2'];
        yield 'previous identity' => ['article-saved:1'];
    }
}
