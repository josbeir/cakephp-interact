<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\UpdatedPaths;
use LogicException;

/**
 * Exercises action-only outcomes and redirect paths whose records can no longer render.
 * Invalid hook modes deliberately attempt forbidden navigation outside an action.
 */
#[Render(element: 'Outcome')]
class OutcomeIsland extends Island
{
    #[Prop]
    public string $hook = '';

    #[State(writable: true)]
    public string $draft = '';

    #[State]
    public int $exports = 0;

    #[State]
    public bool $deleted = false;

    public function authorize(IslandContext $context): void
    {
        if ($this->hook === 'authorize') {
            $this->redirect('/forbidden');
        }
    }

    public function mount(): void
    {
        if ($this->hook === 'mount') {
            $this->downloadUrl('/forbidden');
        }
    }

    public function updated(UpdatedPaths $paths): void
    {
        if ($this->hook === 'updated') {
            $this->redirect('/forbidden');
        }
    }

    public function viewData(): array
    {
        if ($this->hook === 'render') {
            $this->redirect('/forbidden');
        }

        if ($this->deleted) {
            throw new LogicException('Deleted records cannot render.');
        }

        if ($this->hook === 'render-error' && $this->exports > 0) {
            $this->setErrors(['_form' => ['Render failed.']]);
        }

        return [];
    }

    #[Action]
    public function leave(string $url, bool $replace = false): void
    {
        $this->deleted = true;
        $this->dispatch('saved');
        $this->redirect($url, $replace);
    }

    #[Action]
    public function download(string $url): void
    {
        $this->downloadUrl($url);
        $this->exports++;
        $this->dispatch('exported');
    }

    #[Action]
    public function invalid(string $kind): void
    {
        if ($kind === 'download') {
            $this->downloadUrl('/file');
        } else {
            $this->redirect('/next');
        }

        $this->setErrors(['draft' => ['Required.']]);
    }

    #[Action]
    public function twice(): void
    {
        $this->redirect('/one');
        $this->downloadUrl('/two');
    }
}
