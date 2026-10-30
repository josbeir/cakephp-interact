<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Runtime;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\ServerRequest;
use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\UpdatedPaths;

/**
 * Observes native request identity throughout fresh island lifecycles.
 * Request objects remain test-only observations, outside declared browser state.
 */
#[Render(template: 'unused')]
class RequestIsland extends Island
{
    /** @var list<array{hook: string, request: ServerRequest, island: RequestIsland}> */
    public static array $observations = [];

    public static int $constructed = 0;

    #[State(writable: true)]
    public string $value = '';

    /**
     * Exercise a custom constructor without requiring a parent constructor call.
     */
    public function __construct()
    {
        static::$constructed++;
    }

    public function initialize(): void
    {
        $this->observe('initialize');
    }

    public function authorize(IslandContext $context): void
    {
        $this->observe('authorize');
        if ($context->request->getAttribute('denied') === true) {
            throw new ForbiddenException('Denied.');
        }
    }

    public function mount(): void
    {
        $this->observe('mount');
    }

    public function updated(UpdatedPaths $paths): void
    {
        $this->observe('updated');
    }

    #[Action]
    public function record(): void
    {
        $this->observe('action');
    }

    #[Action]
    public function notify(): void
    {
        $this->getRequest()->getFlash()->success('Saved.');
        $this->redirect('/saved');
    }

    /**
     * Observe rendering without adding computed values to the transport.
     *
     * @return array<string, mixed> No additional view data is needed.
     */
    public function viewData(): array
    {
        $this->observe('viewData');

        return [];
    }

    protected function observe(string $hook): void
    {
        static::$observations[] = ['hook' => $hook, 'request' => $this->getRequest(), 'island' => $this];
    }
}
