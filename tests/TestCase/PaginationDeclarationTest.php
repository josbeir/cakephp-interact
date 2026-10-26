<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\TestSuite\TestCase;
use Interact\Attribute\Pagination;
use Interact\Discovery\Definition;
use Interact\Discovery\Registry;
use Interact\Pagination\Configuration;
use Interact\Pagination\PaginationProperty;
use Interact\Pagination\PaginationState;
use Interact\Pagination\Selection;
use Interact\Runtime\State;
use Interact\Test\Fixture\CounterIsland;
use Interact\Test\Fixture\Pagination\PageIsland;
use Interact\Test\Fixture\Pagination\TwoListingsIsland;
use Interact\Url\UrlState;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Verifies the closed paging codec, independent query ownership, and fresh typed initialization.
 * Declared options stay server-side while scalar fields follow the normal state and URL contracts.
 */
class PaginationDeclarationTest extends TestCase
{
    public function testDefaultsAreInitializedForEveryFreshInstanceAndOnlyScalarsAreExported(): void
    {
        $definition = new Definition(PageIsland::class);
        $registry = new Registry();
        $first = $registry->create($definition);
        $second = $registry->create($definition);
        static::assertInstanceOf(PageIsland::class, $first);
        static::assertInstanceOf(PageIsland::class, $second);
        static::assertNotSame($first->paging, $second->paging);
        static::assertSame(5, $first->paging->limit);
        $first->paging->page = 3;
        static::assertSame(1, $second->paging->page);
        static::assertSame(
            ['page' => 3, 'sort' => '', 'direction' => 'asc'],
            new State()->export($first, $definition)['paging'],
        );
    }

    public function testSelectionRequiresAnOwnedObjectWhenSeveralListingsAreDeclared(): void
    {
        $definition = new Definition(TwoListingsIsland::class);
        $island = new Registry()->create($definition);
        static::assertInstanceOf(TwoListingsIsland::class, $island);
        $configuration = new Configuration($definition);
        static::assertSame('paging', $configuration->select($island, $island->paging)->name);
        static::assertSame('other', $configuration->select($island, $island->other)->name);
        $this->expectException(InvalidArgumentException::class);
        $configuration->select($island, null);
    }

    public function testAnUnownedStateCannotSelectAnotherListing(): void
    {
        $definition = new Definition(TwoListingsIsland::class);
        $this->expectException(InvalidArgumentException::class);
        new Configuration($definition)->select(new Registry()->create($definition), new PaginationState());
    }

    public function testCustomDefaultsAliasesAndSelectiveHistoryUseNestedStatePaths(): void
    {
        $property = new PaginationProperty(
            new ReflectionProperty(PageIsland::class, 'paging'),
            new Pagination(
                settings: ['limit' => 10],
                url: ['page' => 'p', 'sort' => 'order', 'limit' => 'size'],
                history: ['page', 'limit'],
                editableLimit: true,
                defaults: ['page' => 2, 'sort' => 'title', 'direction' => 'desc'],
            ),
        );
        $island = new PageIsland();
        $property->initialize($island);
        static::assertSame(2, $island->paging->page);
        static::assertSame('title', $island->paging->sort);
        static::assertSame('desc', $island->paging->direction);
        static::assertSame(10, $island->paging->limit);
        $bindings = $property->bindings();
        static::assertSame(['paging.page', 'paging.sort', 'paging.limit'], array_keys($bindings));
        static::assertSame('p', $bindings['paging.page']->name);
        static::assertTrue($bindings['paging.page']->history);
        static::assertFalse($bindings['paging.sort']->history);
        static::assertSame(['type' => 'int', 'nullable' => false], $property->writableFields()['paging.limit']);
        $owned = $island->paging;
        $property->assign($island, ['page' => 4, 'sort' => '', 'direction' => 'asc', 'limit' => 20]);
        static::assertSame($owned, $island->paging);
        static::assertSame(4, $island->paging->page);
        static::assertSame(20, $island->paging->limit);
    }

    public function testConventionalUrlNamesAndPrefixesRemainIndependentOfThePhpProperty(): void
    {
        $property = new PaginationProperty(
            new ReflectionProperty(PageIsland::class, 'paging'),
            new Pagination(url: true),
        );
        static::assertSame('page', $property->bindings()['paging.page']->name);
        $metadata = new UrlState()->metadata(new Definition(TwoListingsIsland::class), 'catalog');
        static::assertSame('catalog_p', $metadata['paging.page']['name']);
        static::assertSame('catalog_other_page', $metadata['other.page']['name']);
    }

    /**
     * Reject every unsupported state shape before it reaches application hooks or actions.
     */
    #[DataProvider('invalidSelections')]
    public function testInvalidSelectionsAreRejected(mixed $value): void
    {
        $selection = new Selection(new Pagination());
        $this->expectException(InvalidArgumentException::class);
        $selection->decode($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSelections(): iterable
    {
        yield 'object' => [new PaginationState()];
        yield 'null' => [null];
        yield 'missing direction' => [['page' => 1, 'sort' => '']];
        yield 'extra settings' => [['page' => 1, 'sort' => '', 'direction' => 'asc', 'settings' => []]];
        yield 'fixed limit is locked' => [['page' => 1, 'sort' => '', 'direction' => 'asc', 'limit' => 5]];
        yield 'string page' => [['page' => '2', 'sort' => '', 'direction' => 'asc']];
        yield 'unsafe page' => [['page' => PHP_INT_MAX, 'sort' => '', 'direction' => 'asc']];
        yield 'array sort' => [['page' => 1, 'sort' => [], 'direction' => 'asc']];
        yield 'null direction' => [['page' => 1, 'sort' => '', 'direction' => null]];
    }

    /**
     * Check trusted declarations fail early when their URL capabilities are inconsistent.
     */
    #[DataProvider('invalidDeclarations')]
    public function testInvalidDeclarationsAreRejected(Pagination $declaration): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaginationProperty(new ReflectionProperty(PageIsland::class, 'paging'), $declaration);
    }

    /**
     * @return iterable<string, array{Pagination}>
     */
    public static function invalidDeclarations(): iterable
    {
        yield 'unknown alias field' => [new Pagination(url: ['unknown' => 'p'])];
        yield 'locked limit alias' => [new Pagination(url: ['limit' => 'size'])];
        yield 'invalid alias' => [new Pagination(url: ['page' => 'bad.key'])];
        yield 'history without urls' => [new Pagination(history: true)];
        yield 'history for unbound field' => [new Pagination(url: ['page' => 'p'], history: ['sort'])];
        yield 'invalid native limit' => [new Pagination(settings: ['limit' => '20'])];
    }

    public function testEditableLimitsRejectNullInsteadOfRestoringTheDefault(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Selection(new Pagination(editableLimit: true))->decode([
            'page' => 1,
            'sort' => '',
            'direction' => 'asc',
            'limit' => null,
        ]);
    }

    public function testInvalidPrefixesAreRejectedBeforeAnyUrlValuesAreAssigned(): void
    {
        $definition = new Definition(PageIsland::class);
        $island = new Registry()->create($definition);
        $this->expectException(InvalidArgumentException::class);
        new UrlState()->initialize($island, $definition, 'page=2', 'invalid.prefix');
    }

    public function testOrdinaryScalarPropertiesCannotPretendToBePagingObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PaginationProperty(new ReflectionProperty(CounterIsland::class, 'count'), new Pagination());
    }
}
