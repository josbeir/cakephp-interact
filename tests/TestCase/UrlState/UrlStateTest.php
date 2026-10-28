<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\UrlState;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Attribute\State;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Runtime\Runtime;
use Interact\Runtime\SnapshotData;
use Interact\Runtime\State as RuntimeState;
use Interact\Runtime\UpdateRequest;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\Rendering\CallbackRenderer;
use Interact\Test\Fixture\Runtime\RuntimeReference;
use Interact\Test\Fixture\UrlState\UrlCollisionIsland;
use Interact\Test\Fixture\UrlState\UrlIsland;
use Interact\Test\Fixture\UrlState\UrlParentIsland;
use Interact\Test\Fixture\UrlState\UrlTypes;
use Interact\Url\Binding;
use Interact\Url\Codec;
use Interact\Url\UrlState;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Verifies URL capabilities through real declaration compilation, signing, and lifecycle execution.
 * Raw query strings never bypass property types, authorization, or action allowlists.
 */
class UrlStateTest extends TestCase
{
    protected function context(string $query = ''): IslandContext
    {
        $request = new ServerRequest([
            'url' => '/search?' . $query,
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
        ]);

        return new IslandContext($request, 'url-test', '/_interact/update');
    }

    protected function runtime(): Runtime
    {
        $registry = new Registry();
        $registry->register(UrlIsland::class);

        return new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('u', 32)),
            new CallbackRenderer(static fn(): string => '<p>URL fixture</p>'),
        );
    }

    public function testQueryInitializesBeforeMountAndPrefixSurvivesSignedUpdates(): void
    {
        $runtime = $this->runtime();
        $context = $this->context('articles_q=cake&articles_page=3&articles_tags[]=one&articles_tags[]=two');
        $mount = $runtime->mount('Url', [], 'first', $context, 'articles');
        $this->assertSame(['cake', '3'], $mount->state['observed']);
        $this->assertSame(['one', 'two'], $mount->state['tags']);
        $this->assertSame('articles_q', $mount->url['search']['name']);
        $this->assertSame('start', $mount->url['search']['default']);
        $this->assertStringContainsString('data-interact-url=', $mount->html);
        $updated = $runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['search' => 'new'],
            'action' => 'increment',
        ], $context);
        $this->assertSame(1, $updated->state['page']);
        $this->assertSame(1, $updated->state['calls']);
        $this->assertSame($mount->url, $updated->url);
        $this->assertArrayHasKey('url', $updated->payload());
    }

    public function testMalformedQueryEncodingFallsBackBeforeSigningAndRendering(): void
    {
        $result = $this->runtime()->mount(
            'Url',
            [],
            'first',
            $this->context('q=%FF&tags[]=valid&tags[]=%C3&page=3'),
        );
        $this->assertSame('start', $result->state['search']);
        $this->assertSame(['default'], $result->state['tags']);
        $this->assertSame(3, $result->state['page']);
        $this->assertSame(['start', '3'], $result->state['observed']);
        $this->assertNotEmpty($result->snapshot);
    }

    public function testHistoryRestorationReportsTheCompleteSetAndPreservesUnrelatedState(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Url', [], 'first', $context);
        $saved = $runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['draft' => 'keep', 'page' => 3],
        ], $context);
        $restored = $runtime->update([
            'snapshot' => $saved->snapshot,
            'urlRestore' => true,
            'updates' => ['search' => 'earlier', 'page' => 3, 'enabled' => false, 'ratio' => 1.5, 'tags' => []],
        ], $context);
        $this->assertSame(3, $restored->state['page']);
        $this->assertSame('keep', $restored->state['draft']);
        $this->assertSame(0, $restored->state['calls']);
        $this->assertSame(['search', 'page', 'enabled', 'ratio', 'tags'], $restored->state['observed']);
        $normalized = $runtime->update(['snapshot' => $restored->snapshot, 'updates' => ['page' => 900]], $context);
        $this->assertSame(10, $normalized->state['page']);
    }

    public function testQueryValuesAreAuthorizedOnMount(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->runtime()->mount('Url', [], 'first', $this->context('q=denied'));
    }

    public function testHistoryValuesAreAuthorizedBeforeHooks(): void
    {
        $runtime = $this->runtime();
        $context = $this->context();
        $mount = $runtime->mount('Url', [], 'first', $context);
        $this->expectException(ForbiddenException::class);
        $runtime->update([
            'snapshot' => $mount->snapshot,
            'urlRestore' => true,
            'updates' => ['search' => 'denied', 'page' => 1, 'enabled' => false, 'ratio' => 1.5, 'tags' => []],
        ], $context);
    }

    /**
     * @return array<string, array{string, string, mixed}>
     */
    public static function queries(): array
    {
        return [
            'search prefix' => ['search', '?q=word', 'word'],
            'literal question mark' => ['search', '??q=bad', 'start'],
            'empty text' => ['search', 'q=', ''],
            'missing text' => ['search', 'other=one', 'start'],
            'unicode' => ['search', 'q=caf%C3%A9+%26+cake', 'café & cake'],
            'invalid utf8 text' => ['search', 'q=%FF', 'start'],
            'incomplete utf8 text' => ['search', 'q=%C3', 'start'],
            'invalid utf8 list' => ['tags', 'tags[]=valid&tags[]=%FF', ['default']],
            'invalid utf8 key' => ['tags', 'tags[%FF]=bad', ['default']],
            'unrelated invalid utf8' => ['search', 'other=%FF&q=cake', 'cake'],
            'duplicate scalar' => ['search', 'q=one&q=two', 'two'],
            'nested scalar' => ['search', 'q[x]=one', 'start'],
            'true' => ['enabled', 'enabled=1', true],
            'false' => ['enabled', 'enabled=0', false],
            'truthy strings rejected' => ['enabled', 'enabled=false', false],
            'zero' => ['page', 'page=0', 0],
            'negative' => ['page', 'page=-2', -2],
            'invalid integer' => ['page', 'page=abc', 1],
            'overflow' => ['page', 'page=9007199254740992', 1],
            'leading zero' => ['page', 'page=01', 1],
            'fractional integer' => ['page', 'page=1.2', 1],
            'float' => ['ratio', 'ratio=2.25e2', 225.0],
            'invalid float' => ['ratio', 'ratio=INF', 1.5],
            'float overflow' => ['ratio', 'ratio=1e999', 1.5],
            'unsafe integer as float' => ['ratio', 'ratio=9007199254740993', 1.5],
            'missing list' => ['tags', '', ['default']],
            'empty list' => ['tags', 'tags=', []],
            'empty item' => ['tags', 'tags[]=', ['']],
            'list' => ['tags', 'tags[]=one&tags[]=two', ['one', 'two']],
            'indexed list' => ['tags', 'tags[0]=one&tags[1]=two', ['one', 'two']],
            'list duplicates' => ['tags', 'tags[0]=one&tags[0]=two', ['two']],
            'sparse list' => ['tags', 'tags[1]=one', ['default']],
            'nested map' => ['tags', 'tags[x][y]=one', ['x' => ['y' => 'one']]],
            'mixed list' => ['tags', 'tags=&tags[]=one', ['default']],
            'scalar list' => ['tags', 'tags=one', ['default']],
        ];
    }

    #[DataProvider('queries')]
    public function testCodec(string $property, string $query, mixed $expected): void
    {
        $field = new Definition(UrlIsland::class)->properties[$property]->url;
        $this->assertInstanceOf(Binding::class, $field);
        $this->assertSame($expected, new Codec()->read($query, $field->name, $field));
    }

    /**
     * @return list<array{string, State}>
     */
    public static function invalidDeclarations(): array
    {
        return [
            ['text', new State(url: true)],
            ['nullableArray', new State(writable: true, url: true)],
            ['uninitialized', new State(writable: true, url: true)],
            ['numbers', new State(writable: true, url: true)],
            ['unsafe', new State(writable: true, url: true)],
            ['text', new State(writable: true, url: '')],
            ['text', new State(writable: true, url: 'a.b')],
            ['text', new State(writable: true, url: '__proto__')],
        ];
    }

    #[DataProvider('invalidDeclarations')]
    public function testRejectsInvalidDeclarations(string $property, State $state): void
    {
        $this->expectException(InvalidArgumentException::class);
        Binding::fromProperty(new ReflectionProperty(UrlTypes::class, $property), $state);
    }

    public function testRejectsHistoryWithoutUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new State(history: true);
    }

    /**
     * @return list<array<array<string, mixed>>>
     */
    public static function invalidRequests(): array
    {
        return [
            [['urlRestore' => true, 'action' => 'increment']],
            [['urlRestore' => true, 'args' => [1]]],
            [['urlRestore' => true, 'upload' => ['kind' => 'remove', 'field' => 'file']]],
            [['pageQuery' => []]],
            [['pageQuery' => str_repeat('x', 1_048_577)]],
            [['urlRestore' => 'yes']],
        ];
    }

    /**
     * @param array<string, mixed> $extra Invalid envelope additions.
     */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidHistoryEnvelopes(array $extra): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UpdateRequest(['snapshot' => 'signed'] + $extra);
    }

    public function testRejectsPartialOrAdditionalHistoryFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UrlState()->restorationPaths(new Definition(UrlIsland::class), ['search' => 'only']);
    }

    public function testRejectsInvalidSignedPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotData([
            'alias' => 'Url',
            'id' => 'id',
            'key' => 'key',
            'state' => [],
            'children' => [],
            'urlPrefix' => [],
        ]);
    }

    public function testDuplicateQueryAliasesAreRejectedDuringMetadataCompilation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Definition(UrlCollisionIsland::class);
    }

    public function testInvalidUrlValuesCannotBeExported(): void
    {
        $island = new UrlIsland();
        new ReflectionProperty(UrlIsland::class, 'tags')->setValue($island, [42]);
        $this->expectException(InvalidArgumentException::class);
        new RuntimeState()->export($island, new Definition(UrlIsland::class));
    }

    public function testNewChildrenReadPageQueryAndPreservedChildrenKeepTheirPrefix(): void
    {
        $reference = new RuntimeReference();
        $context = $this->context();
        $registry = new Registry();
        $registry->register(UrlIsland::class);
        $registry->register(UrlParentIsland::class);

        $renderer = new CallbackRenderer(static function ($definition, array $data) use ($reference, $context): string {
            if ($definition->declaration->name === 'UrlParent' && is_int($data['calls']) && $data['calls'] > 0) {
                return $reference->runtime->mount('Url', [], 'child', $context, 'child')->html;
            }

            return '<p>Child</p>';
        });
        $runtime = new Runtime($registry, new SnapshotCodec(str_repeat('u', 32)), $renderer);
        $reference->runtime = $runtime;
        $mount = $runtime->mount('UrlParent', [], 'parent', $context, 'parent');
        $shown = $runtime->update([
            'snapshot' => $mount->snapshot,
            'action' => 'increment',
            'pageQuery' => 'child_q=linked&child_page=4',
        ], $context);
        $this->assertStringContainsString('&quot;search&quot;:&quot;linked&quot;', $shown->html);
        $this->assertStringContainsString('&quot;page&quot;:4', $shown->html);
        $preserved = $runtime->update(['snapshot' => $shown->snapshot, 'pageQuery' => 'child_q=other'], $context);
        $this->assertStringContainsString('data-interact-preserve=', $preserved->html);
    }

    public function testInvalidSignedChildPrefixIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotData([
            'alias' => 'UrlParent',
            'id' => 'id',
            'key' => 'key',
            'state' => [],
            'children' => ['key:child' => ['alias' => 'Url', 'id' => 'child', 'props' => [], 'urlPrefix' => []]],
        ]);
    }
}
