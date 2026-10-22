<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;

/**
 * Exercises query initialization, strict scalar types, history hooks, and action isolation.
 * Authorization intentionally observes the initialized query before mount executes.
 */
#[Render(element: 'url')]
class UrlIsland extends Island
{
    #[State(writable: true, url: 'q')]
    public string $search = 'start';

    #[State(writable: true, url: true, history: true)]
    public int $page = 1;

    #[State(writable: true, url: true)]
    public bool $enabled = false;

    #[State(writable: true, url: true)]
    public float $ratio = 1.5;

    /**
     * @var list<string> Ordered filter choices.
     */
    #[State(writable: true, url: true)]
    public array $tags = ['default'];

    #[State(writable: true)]
    public string $draft = '';

    #[State]
    public int $calls = 0;

    /**
     * @var list<string> Lifecycle observations, carried only for assertions.
     */
    #[State]
    public array $observed = [];

    public function authorize(IslandContext $context): void
    {
        if ($this->search === 'denied') {
            throw new ForbiddenException('Access denied.');
        }
    }

    public function mount(): void
    {
        $this->observed = [$this->search, (string)$this->page];
    }

    public function updated(array $paths): void
    {
        $this->observed = $paths;
        if (in_array('search', $paths, true) && !in_array('page', $paths, true)) {
            $this->page = 1;
        }

        $this->page = max(1, min(10, $this->page));
    }

    #[Action]
    public function increment(): void
    {
        $this->calls++;
    }
}
