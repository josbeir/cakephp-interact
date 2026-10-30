<?php
declare(strict_types=1);

namespace TestApp\Island\Composition;

use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use Interact\UpdatedPaths;
use TestApp\Model\Table\ResourcesTable;

/**
 * Exposes one modelable selection while searching through application-owned resource finders.
 * Category changes arrive only from verified parent renders; selection membership is checked in PHP.
 */
class PickerIsland extends Island
{
    protected ResourcesTable $resources;

    #[Prop(reactive: true)]
    public string $category = 'cakephp';

    #[State(writable: true, modelable: true)]
    public ?int $value = null;

    #[State(writable: true)]
    public string $search = '';

    #[State(writable: true)]
    public string $notes = '';

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $this->resources = $this->fetchTable(ResourcesTable::class);
    }

    /**
     * Clear a selection whose category changed without replacing unrelated child drafts.
     *
     * @param UpdatedPaths $paths Accepted prop and state changes.
     */
    public function updated(UpdatedPaths $paths): void
    {
        if ($paths->has('category')) {
            $this->value = null;
        }
    }

    /**
     * Accept only a resource from the currently authorized category scope.
     */
    #[Action]
    public function choose(int $id): void
    {
        $resource = $this->resources
            ->find('filtered', category: $this->category)
            ->where(['Resources.id' => $id])
            ->first();
        if ($resource === null) {
            $this->setErrors(['value' => ['Choose a resource from the current category.']]);

            return;
        }

        $this->value = $id;
    }

    /**
     * Fetch a short search result list after the normal authorization and update lifecycle.
     *
     * @return array{resources: \Cake\Datasource\ResultSetInterface<array-key, \Cake\Datasource\EntityInterface>}
     */
    public function viewData(): array
    {
        return [
            'resources' => $this->resources
                ->find('listing', search: $this->search, category: $this->category)
                ->limit(8)
                ->all(),
        ];
    }
}
