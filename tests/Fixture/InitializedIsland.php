<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use Interact\Attribute\Render;
use Interact\IslandContext;
use Interact\UpdatedPaths;

/**
 * Assigns a request-local Table before authorization without transporting it.
 * Constructor configuration and lifecycle traces reveal setup order on fresh instances.
 */
#[Render(element: 'unused')]
class InitializedIsland extends LazyIsland
{
    use LocatorAwareTrait;

    protected Table $records;

    /**
     * Accept container configuration without requiring a parent constructor call.
     */
    public function __construct(protected string $tableAlias = 'Resources')
    {
        self::$trace[] = 'construct';
    }

    /**
     * Resolve the configured Table before props and state are restored.
     */
    public function initialize(): void
    {
        $this->records = $this->fetchTable($this->tableAlias);
        self::$trace[] = 'initialize:' . $this->query;
    }

    /**
     * Use the initialized dependency before checking current request access.
     */
    public function authorize(IslandContext $context): void
    {
        self::$trace[] = 'table:' . $this->records->getAlias();
        parent::authorize($context);
    }

    /**
     * Observe restored edits after setup and authorization.
     */
    public function updated(UpdatedPaths $paths): void
    {
        self::$trace[] = 'updated:' . $this->query;
    }
}
