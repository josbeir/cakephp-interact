<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\State;

use Interact\Runtime\StateChanges;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies nested hook paths describe values rather than the transport's replacement granularity.
 * Containers retain explicit boundaries when dotted paths cannot represent the change safely.
 */
class StateChangesTest extends TestCase
{
    /**
     * Cover scalar distinctions, nested edits, shape changes, and literal array keys.
     *
     * @return iterable<string, array{mixed, mixed, list<string>}>
     */
    public static function changes(): iterable
    {
        yield 'unchanged scalar' => [null, null, []];
        yield 'false is not zero' => [false, 0, ['data']];
        yield 'null is not missing' => [['value' => null, 'keep' => ''], ['keep' => ''], ['data.value']];
        yield 'add a named field' => [['keep' => ''], ['keep' => '', 'value' => false], ['data.value']];
        yield 'nested field' => [
            ['address' => ['country' => 'BE']],
            ['address' => ['country' => 'NL']],
            ['data.address.country'],
        ];
        yield 'map order only' => [['one' => 1, 'two' => 2], ['two' => 2, 'one' => 1], []];
        yield 'replace container' => [['address' => ['city' => 'Ghent']], ['address' => null], ['data.address']];
        yield 'append list row' => [[['body' => 'first']], [['body' => 'first'], ['body' => 'second']], ['data']];
        yield 'remove list row' => [['first', 'second'], ['first'], ['data']];
        yield 'edit list row' => [[['body' => 'first']], [['body' => 'edited']], ['data.0.body']];
        yield 'list becomes map' => [['first'], ['name' => 'first'], ['data']];
        yield 'empty a map' => [['name' => 'first'], [], ['data']];
        yield 'literal dotted key' => [['a.b' => 'old'], ['a.b' => 'new'], ['data']];
        yield 'literal empty key' => [['' => 'old'], ['' => 'new'], ['data']];
        yield 'unchanged literal key' => [
            ['a.b' => 'keep', 'name' => 'old'],
            ['a.b' => 'keep', 'name' => 'new'],
            ['data.name'],
        ];
    }

    /**
     * Preserve exact changes without expanding unchanged siblings or coercing values.
     *
     * @param list<string> $expected Exact changed paths.
     */
    #[DataProvider('changes')]
    public function testReportsChangedValues(mixed $previous, mixed $current, array $expected): void
    {
        $this->assertSame($expected, new StateChanges()->paths($previous, $current, 'data'));
    }
}
