<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Runtime;

use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Interact\Discovery\Definition;
use Interact\Test\Fixture\Runtime\PlainIsland;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies default hooks, inherited Table access, and browser-event validation.
 * These boundaries run through the same declarations and runtime contracts as application islands.
 */
class DefaultIslandBehaviorTest extends TestCase
{
    public function testInheritedTableLocatorRemainsServerOwnedAndInstanceScoped(): void
    {
        $globalLocator = new PlainIsland()->getTableLocator();
        $locator = new TableLocator();
        $table = new Table([
            'alias' => 'Example',
            'connection' => new Connection(['driver' => Sqlite::class, 'database' => ':memory:']),
        ]);
        $locator->set('Example', $table);
        $island = new PlainIsland();
        $this->assertSame($island, $island->setTableLocator($locator));
        $this->assertSame($locator, $island->getTableLocator());
        $this->assertSame($table, $island->fetchTable('Example'));
        $this->assertSame($globalLocator, new PlainIsland()->getTableLocator());

        $definition = new Definition(PlainIsland::class);
        $this->assertSame([], $definition->properties);
        $this->assertSame([], $definition->actions);
    }

    public function testDefaultViewDataAndInvalidBrowserEvent(): void
    {
        $island = new PlainIsland();
        $this->assertSame([], $island->viewData());
        $this->expectException(InvalidArgumentException::class);
        $island->dispatch('bad name');
    }
}
