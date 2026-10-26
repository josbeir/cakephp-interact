<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Interact\Attribute\State;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Pagination\Links;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;
use Interact\Test\Fixture\CallbackRenderer;
use Interact\Test\Fixture\StructuredUrlTypes;
use Interact\Url\ArrayValue;
use Interact\Url\Binding;
use Interact\Url\Codec;
use Interact\Url\QueryWriter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TestApp\Island\UrlFiltersIsland;

/**
 * Runs the same query examples as the browser codec, including canonical native-link round trips.
 * Shared fixtures make PHP and JavaScript disagreement visible before browser initialization.
 */
class UrlCodecTest extends TestCase
{
    /**
     * Load query examples shared with Vitest.
     *
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function examples(): iterable
    {
        $contents = file_get_contents(dirname(__DIR__) . '/Fixture/url-values.json');
        static::assertIsString($contents);
        /**
         * @var array{cases: list<array{name: string, property: string, query: string, expected: mixed}>} $fixture
         */
        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            yield $case['name'] => [$case['property'], $case['query'], $case['expected']];
        }
    }

    /**
     * Verify the decoded value and its lossless canonical link against application defaults.
     */
    #[DataProvider('examples')]
    public function testSharedQuery(string $property, string $query, mixed $expected): void
    {
        $binding = new Binding(
            new ReflectionProperty(StructuredUrlTypes::class, $property),
            new State(writable: true, url: 'value'),
        );
        $codec = new Codec();
        static::assertSame($expected, $codec->read($query, 'value', $binding));
        $encoded = new QueryWriter()->write('value', $expected);
        static::assertSame($expected, $codec->read($encoded, 'value', $binding));
    }

    /**
     * Keep nullable selections, empty nested filters, and unrelated duplicates in native page links.
     */
    public function testNativePaginationRetainsStructuredValues(): void
    {
        $fields = [
            'query' => ['name' => 'q', 'type' => 'string', 'default' => 'all', 'history' => false, 'nullable' => true],
            'filters' => [
                'name' => 'filters',
                'type' => 'array',
                'default' => ['tags' => ['initial']],
                'history' => false,
            ],
            'page' => ['name' => 'page', 'type' => 'int', 'default' => 1, 'history' => true],
        ];
        $values = ['query' => null, 'filters' => ['tags' => [], 'status' => ''], 'page' => 1];
        $links = new Links('/search', 'external=one&external=two&q=old&filters[tags][]=old', $fields, $values);
        $url = $links->generate(['page' => 2], ['page']);
        static::assertStringContainsString('external=one&external=two', $url);
        static::assertStringContainsString('q%5B~null%5D=1', $url);
        static::assertStringContainsString('filters%5Btags%5D%5B~empty%5D=1', $url);
        static::assertStringContainsString('filters%5Bstatus%5D=', $url);
        static::assertStringContainsString('page=2', $url);
        static::assertStringNotContainsString('old', $url);
    }

    /**
     * Omit equivalent map defaults while keeping numeric-looking string changes and list order visible.
     */
    public function testNativeDefaultComparisonPreservesTypesAndListOrder(): void
    {
        $default = ['code' => '01', 'tags' => ['a', 'b']];
        $fields = ['filters' => ['name' => 'filters', 'type' => 'array', 'default' => $default, 'history' => false]];
        $same = new Links('/search', '', $fields, ['filters' => ['tags' => ['a', 'b'], 'code' => '01']]);
        static::assertSame('/search', $same->generate([], []));
        $changed = new Links('/search', '', $fields, ['filters' => ['tags' => ['b', 'a'], 'code' => '1']]);
        static::assertStringContainsString('filters%5Bcode%5D=1', $changed->generate([], []));
        static::assertFalse(ArrayValue::same(['code' => '01'], ['other' => '01']));
        static::assertFalse(ArrayValue::same(['code' => '01'], ['code' => '01', 'other' => '']));
        static::assertFalse(ArrayValue::same(['a'], ['code' => 'a']));
    }

    /**
     * Accept nullable renderless state and restore whole structured selections without replaying actions.
     */
    public function testRuntimeRestoresStructuredStateAndRejectsNonStringLeaves(): void
    {
        $registry = new Registry();
        $registry->register(UrlFiltersIsland::class);

        $context = new IslandContext(
            new ServerRequest([
                'url' => '/demo/url-state?q=&filters[price][min]=010',
                'session' => new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]),
            ]),
            'url-values',
            '/_interact/update',
        );
        $runtime = new Runtime(
            $registry,
            new SnapshotCodec(str_repeat('u', 32)),
            new CallbackRenderer(static fn(): string => '<p>URL filters</p>'),
        );
        $mount = $runtime->mount('UrlFilters', [], null, $context);
        static::assertSame('', $mount->state['query']);
        static::assertTrue($mount->url['query']['nullable'] ?? null);
        static::assertSame(
            ['status' => '', 'tags' => ['initial'], 'price' => ['min' => '010', 'max' => '100']],
            $mount->state['filters'],
        );
        $changed = $runtime->update([
            'snapshot' => $mount->snapshot,
            'updates' => ['filters.price.min' => '020'],
        ], $context);
        static::assertSame(
            ['status' => '', 'tags' => ['initial'], 'price' => ['min' => '020', 'max' => '100']],
            $changed->state['filters'],
        );
        $null = $runtime->update([
            'snapshot' => $changed->snapshot,
            'action' => 'selectNull',
            'updates' => ['notes' => 'keep'],
        ], $context);
        static::assertNull($null->state['query']);
        static::assertNull($null->payload()['html']);
        $restored = $runtime->update([
            'snapshot' => $null->snapshot,
            'urlRestore' => true,
            'updates' => [
                'query' => 'restored',
                'filters' => ['status' => 'published', 'tags' => [], 'price' => ['min' => '0', 'max' => '100']],
            ],
        ], $context);
        static::assertSame('keep', $restored->state['notes']);
        static::assertSame(1, $restored->state['calls']);
        static::assertSame(
            ['status' => 'published', 'tags' => [], 'price' => ['min' => '0', 'max' => '100']],
            $restored->state['filters'],
        );
        $this->expectException(InvalidArgumentException::class);
        $runtime->update([
            'snapshot' => $restored->snapshot,
            'updates' => ['filters.price.min' => 10],
            'action' => 'apply',
        ], $context);
    }

    /**
     * Bound both accepted trees and raw queries before they can become transported state.
     */
    public function testRejectsExcessiveValues(): void
    {
        $binding = new Binding(
            new ReflectionProperty(StructuredUrlTypes::class, 'items'),
            new State(writable: true, url: 'value'),
        );
        static::assertSame(['default'], new Codec()->read('value=' . str_repeat('x', 1_048_576), 'value', $binding));
        $value = 'leaf';
        for ($depth = 0; $depth < 33; $depth++) {
            $value = [$value];
        }

        static::assertFalse(ArrayValue::accepts($value));
        $this->expectException(InvalidArgumentException::class);
        new QueryWriter()->write('value', $value);
    }
}
