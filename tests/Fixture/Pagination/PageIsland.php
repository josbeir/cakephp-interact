<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Pagination;

use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;

/**
 * Exercises native pagination over scoped real SQLite queries without transporting result rows.
 * Server props select test configuration; authorization always precedes query evaluation.
 */
#[Render(template: 'island/pagination')]
class PageIsland extends Island
{
    use LocatorAwareTrait;

    #[State(writable: true, url: 'p', history: true)]
    public int $page = 1;

    #[State(writable: true, url: 'q')]
    public string $search = '';

    /**
     * @var array<string, mixed> Server-selected pagination settings.
     */
    #[Prop]
    public array $settings = [];

    #[Prop]
    public bool $twice = false;

    #[Prop]
    public bool $allowed = true;

    /**
     * @var array<string, mixed> Native defaults overridden by per-call configuration.
     */
    protected array $paginate = [
        'limit' => 5,
        'maxLimit' => 10,
        'order' => ['Resources.id' => 'asc'],
        'sortableFields' => ['title' => 'Resources.title'],
    ];

    /**
     * Guard mount and update requests independently of signed state integrity.
     */
    public function authorize(IslandContext $context): void
    {
        if (!$this->allowed || $context->request->getAttribute('denied') === true) {
            throw new ForbiddenException('Listing access denied.');
        }
    }

    /**
     * Prove renderless actions do not query or clamp pagination state.
     */
    #[Action(render: false)]
    public function changePage(int $page): void
    {
        $this->page = $page;
    }

    /**
     * Return the application-scoped query as a native paginated result for helper rendering.
     *
     * @return array<string, mixed> Request-local native results only.
     */
    public function viewData(): array
    {
        $query = $this->fetchTable('Resources')
            ->find('matching', search: $this->search)
            ->where(['Resources.category' => 'cakephp']);
        $rows = $this->paginate($query, $this->settings);
        if ($this->twice) {
            $this->paginate($query);
        }

        return ['rows' => $rows];
    }
}
