<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\State;

use Interact\Event\BrowserEvent;
use Interact\Runtime\Values;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps numeric state, arguments, and event details within browser JSON precision.
 * Larger identifiers remain available through explicit string representations.
 */
class NumericTransportTest extends TestCase
{
    /**
     * Supply safe boundaries, ordinary fractions, and large string identifiers.
     *
     * @return iterable<string, array{int|float|string}>
     */
    public static function accepted(): iterable
    {
        yield 'maximum' => [9_007_199_254_740_991];
        yield 'minimum' => [-9_007_199_254_740_991];
        yield 'float boundary' => [9_007_199_254_740_991.0];
        yield 'fraction' => [1.5];
        yield 'zero' => [0];
        yield 'string ID' => ['9007199254740993'];
    }

    /**
     * Preserve accepted JSON values without converting numeric strings to numbers.
     */
    #[DataProvider('accepted')]
    public function testAcceptedValuesRemainUnchanged(int|float|string $value): void
    {
        $event = new BrowserEvent('selected', ['id' => $value]);
        static::assertSame(['id' => $value], $event->detail);
        static::assertEquals($event->detail, json_decode(json_encode($event->detail, JSON_THROW_ON_ERROR), true));
    }

    /**
     * Supply values that would lose integer precision during browser JSON parsing.
     *
     * @return iterable<string, array{int|float}>
     */
    public static function unsafe(): iterable
    {
        yield 'first unsafe' => [9_007_199_254_740_992];
        yield 'rounded ID' => [9_007_199_254_740_993];
        yield 'negative' => [-9_007_199_254_740_992];
        yield 'PHP maximum' => [PHP_INT_MAX];
        yield 'float form' => [9_007_199_254_740_992.0];
        yield 'large exponent' => [1e20];
    }

    /**
     * Reject unsafe numbers recursively before they can become transported state or arguments.
     */
    #[DataProvider('unsafe')]
    public function testUnsafeNestedValuesAreRejected(int|float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('browser precision');
        Values::validate(['nested' => ['id' => $value]]);
    }

    /**
     * Reject a large event ID before JSON encoding could silently round it in a listener.
     */
    public function testLargeEventIdentifiersRequireStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BrowserEvent('selected', ['id' => 9_007_199_254_740_993]);
    }
}
