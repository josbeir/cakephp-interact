<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\State;

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

        $this->assertTrue($paths->has('search'));
        $this->assertTrue($paths->has('data.country'));
        $this->assertTrue($paths->has('items.0.name'));
        $this->assertFalse($paths->has('page'));
        $this->assertFalse($paths->has('Search'));
        $this->assertFalse($paths->has('data'));
        $this->assertFalse($paths->has('data.country.code'));
        $this->assertFalse($paths->has('data.city'));
        $this->assertFalse($paths->has('items.*.name'));
    }

    public function testAnyMembershipAcceptsOneOrMoreAlternatives(): void
    {
        $paths = new UpdatedPaths(['search', 'category']);

        $this->assertTrue($paths->hasAny('search'));
        $this->assertTrue($paths->hasAny('page', 'category', 'sort'));
        $this->assertFalse($paths->hasAny('page', 'sort'));
        $this->assertFalse($paths->hasAny());
    }

    public function testEmptyPathsHaveNoMatchesAndCanBeIterated(): void
    {
        $paths = new UpdatedPaths([]);

        $this->assertFalse($paths->has('search'));
        $this->assertFalse($paths->hasAny('search', 'page'));
        $this->assertSame([], $paths->toArray());
        $this->assertSame([], iterator_to_array($paths));
    }

    public function testArraysAndIteratorsPreserveOrderWithoutMutatingTheObject(): void
    {
        $reported = ['scope', 'data.country', 'page'];
        $paths = new UpdatedPaths($reported);
        $reported[] = 'search';
        $copy = $paths->toArray();
        $copy[0] = 'category';

        $this->assertSame(['scope', 'data.country', 'page'], $paths->toArray());
        $this->assertSame(['scope', 'data.country', 'page'], iterator_to_array($paths));
        $this->assertSame(['scope', 'data.country', 'page'], iterator_to_array($paths));
        $this->assertFalse($paths->has('search'));
        $this->assertFalse($paths->has('category'));
    }
}
