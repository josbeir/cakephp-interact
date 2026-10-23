<?php
declare(strict_types=1);

namespace TestApp\Island\Composition;

use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use TestApp\Model\Table\ResourcesTable;

/**
 * Owns a resource selection draft assembled from independently interactive child inputs.
 * The review action validates membership explicitly; synchronization never saves a record.
 */
class OrderIsland extends Island
{
    use LocatorAwareTrait;

    protected ResourcesTable $resources;

    #[Prop]
    public string $pickerLoading = 'eager';

    #[State(writable: true)]
    public string $category = 'cakephp';

    /**
     * @var array{resourceId: int|null, startsAt: string, endsAt: string} Parent-owned form draft.
     */
    #[State(writable: true)]
    public array $data = ['resourceId' => null, 'startsAt' => '', 'endsAt' => ''];

    #[State]
    public string $reviewed = '';

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $table = $this->fetchTable('Resources');
        assert($table instanceof ResourcesTable, 'Configure the application Resources table.');
        $this->resources = $table;
    }

    /**
     * Check current resource membership rather than trusting the child input or its snapshot.
     */
    #[Action]
    public function review(): void
    {
        if (!is_int($this->data['resourceId'] ?? null)) {
            $this->setErrors(['data.resourceId' => ['Choose a resource from the current category.']]);

            return;
        }

        $resource = $this->resources
            ->find('filtered', category: $this->category)
            ->where(['Resources.id' => $this->data['resourceId']])
            ->first();
        if ($resource === null) {
            $this->setErrors(['data.resourceId' => ['Choose a resource from the current category.']]);

            return;
        }

        $this->reviewed = (string)$resource->get('title');
    }

    /**
     * Demonstrate accepted parent PHP changes flowing back through model bindings.
     */
    #[Action(render: false)]
    public function clear(): void
    {
        $this->data = ['resourceId' => null, 'startsAt' => '', 'endsAt' => ''];
        $this->reviewed = '';
    }

    /**
     * Supply existing application choices without transporting entities or queries.
     *
     * @return array{categories: array<string, string>}
     */
    public function viewData(): array
    {
        return ['categories' => ResourcesTable::CATEGORIES];
    }
}
