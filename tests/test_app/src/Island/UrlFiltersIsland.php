<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Demonstrates reloadable nullable selections and nested string filters without a URL schema.
 * Ordinary notes remain a separate draft while history restores only the declared query state.
 */
class UrlFiltersIsland extends Island
{
    #[Prop]
    public string $controlPrefix = 'first';

    #[State(writable: true, url: 'q', history: true)]
    public ?string $query = 'all';

    /**
     * @var array{status: string, tags: list<string>, price: array{min: string, max: string}}
     */
    #[State(writable: true, url: true, history: true)]
    public array $filters = ['status' => '', 'tags' => ['initial'], 'price' => ['min' => '', 'max' => '100']];

    #[State(writable: true)]
    public string $notes = '';

    #[State]
    public int $calls = 0;

    /**
     * Apply the form's current draft without implicitly saving application data.
     */
    #[Action]
    public function apply(): void
    {
        $this->calls++;
    }

    /**
     * Accept a null selection and update its URL without rendering the template.
     */
    #[Action(render: false)]
    public function selectNull(): void
    {
        $this->query = null;
        $this->calls++;
    }

    /**
     * Clear a nested list explicitly, including a nonempty declared default.
     */
    #[Action]
    public function clearTags(): void
    {
        $this->filters['tags'] = [];
        $this->calls++;
    }
}
