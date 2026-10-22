<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Attribute\Upload;
use Interact\Island;
use Interact\IslandContext;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Exercises render policy through actual discovery, authorization, and fresh request instances.
 * Static counters observe view evaluation only and never enter signed application state.
 */
#[AsIsland('renderless', element: 'unused')]
class RenderlessIsland extends Island
{
    public static int $views = 0;

    public static bool $allowed = true;

    #[State(writable: true, url: 'title')]
    public string $title = '';

    #[State]
    public int $count = 0;

    #[Upload]
    public ?UploadedFileInterface $file = null;

    /**
     * Check current permission on every mount and update.
     */
    public function authorize(IslandContext $context): void
    {
        if (!static::$allowed) {
            throw new RuntimeException('Permission revoked.');
        }
    }

    /**
     * Count successful actions and publish an event without generating HTML.
     */
    #[Action(render: false)]
    public function remember(): void
    {
        $this->count++;
        $this->dispatch('remembered', ['count' => $this->count]);
    }

    /**
     * Choose rendering conditionally on the supplied amount.
     */
    #[Action]
    public function conditional(int $amount): void
    {
        $this->count += $amount;
        if ($amount === 1) {
            $this->skipRender();
            $this->skipRender();
        }
    }

    /**
     * Report feedback that must override a declared renderless preference.
     */
    #[Action(render: false)]
    public function invalid(): void
    {
        $this->setErrors(['title' => ['Required.']]);
        $this->dispatch('suppressed');
    }

    /**
     * Combine the render policy with a native download effect.
     */
    #[Action(render: false)]
    public function download(): void
    {
        $this->remember();
        $this->downloadUrl('/report');
    }

    /**
     * Keep navigation terminal regardless of the rendering preference.
     */
    #[Action(render: false)]
    public function leave(): void
    {
        $this->redirect('/destination');
    }

    /**
     * Observe whether the runtime evaluates view data.
     *
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        static::$views++;

        return [];
    }
}
