<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\UpdatedPaths;
use PHPUnit\Framework\TestCase;

/**
 * Verifies lifecycle path queries preserve exact membership and immutable contents.
 *
 * Nested paths remain distinct from parents, siblings, and wildcard expressions.
 */
class UpdatedPathsTest extends TestCase
{
    public function testMembershipMatchesExactPaths(): void
    {
        $paths = new UpdatedPaths(['search', 'data.country', 'items.0.name']);

        static::assertTrue($paths->has('search'));
        static::assertTrue($paths->has('data.country'));
        static::assertTrue($paths->has('items.0.name'));
        static::assertFalse($paths->has('page'));
        static::assertFalse($paths->has('Search'));
        static::assertFalse($paths->has('data'));
        static::assertFalse($paths->has('data.country.code'));
        static::assertFalse($paths->has('data.city'));
        static::assertFalse($paths->has('items.*.name'));
    }

    public function testAnyMembershipAcceptsOneOrMoreAlternatives(): void
    {
        $paths = new UpdatedPaths(['search', 'category']);

        static::assertTrue($paths->hasAny('search'));
        static::assertTrue($paths->hasAny('page', 'category', 'sort'));
        static::assertFalse($paths->hasAny('page', 'sort'));
        static::assertFalse($paths->hasAny());
    }

    public function testEmptyPathsHaveNoMatchesAndCanBeIterated(): void
    {
        $paths = new UpdatedPaths([]);

        static::assertFalse($paths->has('search'));
        static::assertFalse($paths->hasAny('search', 'page'));
        static::assertSame([], $paths->toArray());
        static::assertSame([], iterator_to_array($paths));
    }

    public function testArraysAndIteratorsPreserveOrderWithoutMutatingTheObject(): void
    {
        $reported = ['scope', 'data.country', 'page'];
        $paths = new UpdatedPaths($reported);
        $reported[] = 'search';
        $copy = $paths->toArray();
        $copy[0] = 'category';

        static::assertSame(['scope', 'data.country', 'page'], $paths->toArray());
        static::assertSame(['scope', 'data.country', 'page'], iterator_to_array($paths));
        static::assertSame(['scope', 'data.country', 'page'], iterator_to_array($paths));
        static::assertFalse($paths->has('search'));
        static::assertFalse($paths->has('category'));
    }
}
