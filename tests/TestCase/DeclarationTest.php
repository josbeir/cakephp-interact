<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Interact\Attribute\Render;
use Interact\Discovery\Registry;
use Interact\Test\Fixture\ConflictingStateIsland;
use Interact\Test\Fixture\CounterIsland;
use Interact\Test\Fixture\DuplicateIsland;
use Interact\Test\Fixture\HiddenActionIsland;
use Interact\Test\Fixture\HiddenStateIsland;
use Interact\Test\Fixture\LifecycleActionIsland;
use Interact\Test\Fixture\StaticActionIsland;
use Interact\Test\Fixture\StaticStateIsland;
use Interact\Test\Fixture\UnlocatedIsland;
use Interact\Test\Fixture\UnsupportedStateIsland;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates the public registration contract and rejects ambiguous or unsafe declaration shapes.
 */
class DeclarationTest extends TestCase
{
    /**
     * Provide invalid class declarations for registration-boundary tests.
     *
     * @return iterable<string, array{class-string}> Invalid extension declarations.
     */
    public static function declarations(): iterable
    {
        foreach ([
            StaticStateIsland::class,
            HiddenStateIsland::class,
            UnsupportedStateIsland::class,
            ConflictingStateIsland::class,
            StaticActionIsland::class,
            HiddenActionIsland::class,
            LifecycleActionIsland::class,
            UnlocatedIsland::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    #[DataProvider('declarations')]
    public function testRejectsInvalidDeclarations(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Registry()->register($class);
    }

    public function testRejectsDuplicateAliases(): void
    {
        $registry = new Registry();
        $registry->register(CounterIsland::class);
        $this->expectException(InvalidArgumentException::class);
        $registry->register(DuplicateIsland::class, 'Counter');
    }

    public function testRejectsUnknownAlias(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Registry()->definition(CounterIsland::class);
    }

    public function testRejectsUnrelatedClasses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Registry()->register(self::class);
    }

    public function testRejectsInvalidAliases(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Registry()->definition('bad name');
    }

    public function testRejectsAmbiguousTemplates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Render(template: 'file', element: 'file');
    }

    public function testAcceptsInferredTemplates(): void
    {
        $declaration = new Render();
        static::assertNull($declaration->template);
        static::assertNull($declaration->element);
    }

    public function testAcceptsNativeElementDeclarations(): void
    {
        $declaration = new Render(element: 'sample');
        static::assertSame('sample', $declaration->element);
    }

    public function testRejectsUnsafeRootTags(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Render(element: 'sample', tag: 'script');
    }
}
