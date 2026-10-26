<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Streaming;

use Cake\Http\Exception\ForbiddenException;
use Closure;
use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\UpdatedPaths;
use RuntimeException;

/**
 * Exercises streaming capabilities, lifecycle ordering, and failures with observable application work.
 */
#[Render(template: 'island/streaming')]
class StreamingIsland extends Island
{
    public static bool $allowed = true;

    /** @var list<string> */
    public static array $lifecycle = [];

    public static ?Closure $afterChunk = null;

    #[State(writable: true)]
    public string $answer = '';

    /**
     * Read lifecycle observations without retaining a narrowed static property between callbacks.
     *
     * @return list<string>
     */
    public static function lifecycle(): array
    {
        return self::$lifecycle;
    }

    /**
     * Check current permissions before any observable hooks or output.
     */
    public function authorize(IslandContext $context): void
    {
        self::$lifecycle[] = 'authorize';
        if (!self::$allowed) {
            throw new ForbiddenException('Denied');
        }
    }

    /**
     * Observe edited state before entering the action capability.
     */
    public function updated(UpdatedPaths $paths): void
    {
        self::$lifecycle[] = 'updated';
        if ($this->answer === 'hook-stream') {
            $this->stream('answer', 'forbidden');
        }
    }

    /**
     * Emit provisional text, HTML, and progress before retaining the final answer in state.
     */
    #[Action(stream: true)]
    public function generate(string $mode = 'success'): void
    {
        self::$lifecycle[] = 'action';
        $this->stream('answer', '', replace: true);
        $this->stream('answer', '<unsafe> €');
        if (self::$afterChunk instanceof Closure) {
            (self::$afterChunk)();
        }

        $this->streamElement('results', 'stream_chunk', ['text' => '<escaped>']);
        $this->streamEvent('progress', ['percent' => 50, 'empty' => '', 'zero' => 0, 'null' => null, 'false' => false]);
        $this->streamEvent('ready');
        if ($mode === 'failure') {
            throw new RuntimeException('Private diagnostic');
        }

        $this->answer = 'Complete';
        if ($mode === 'invalid') {
            $this->setErrors(['answer' => ['Invalid answer.']]);
        }

        $this->dispatch('finished');
    }

    /**
     * Keep streamed DOM while accepting signed state without a final render.
     */
    #[Action(render: false, stream: true)]
    public function retain(): void
    {
        $this->stream('answer', 'Retained', replace: true);
        $this->answer = 'Retained';
    }

    /**
     * Exercise terminal browser effects through a streamed response.
     */
    #[Action(stream: true)]
    public function leave(string $mode = 'redirect'): void
    {
        $this->streamEvent('leaving');
        if ($mode === 'download') {
            $this->downloadUrl('/report');
        } else {
            $this->redirect('/next');
        }
    }

    /**
     * Reject attempts to stream from ordinary declared actions.
     */
    #[Action]
    public function ordinary(): void
    {
        $this->streamEvent('invalid');
    }
}
