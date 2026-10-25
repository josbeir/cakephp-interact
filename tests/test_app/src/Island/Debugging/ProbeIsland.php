<?php
declare(strict_types=1);

namespace TestApp\Island\Debugging;

use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Attribute\Upload;
use Interact\Island;
use Interact\IslandContext;
use Interact\UpdatedPaths;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use function Cake\Error\debug;

/**
 * Exercises native debugging through the real application's HTTP and browser boundaries.
 *
 * This probe owns no persisted resources. Its deliberately unsafe output verifies
 * that diagnostics cannot become executable application markup.
 */
class ProbeIsland extends Island
{
    #[Prop]
    public bool $templateDump = false;

    #[State(writable: true)]
    public string $value = 'Initial';

    #[Upload]
    public ?UploadedFileInterface $file = null;

    /**
     * Exercise output before props and state assignment without accessing protected resources.
     */
    public function initialize(): void
    {
        debug('initialized', false);
    }

    /**
     * Produce lifecycle output only on update requests, never on the hosting document.
     */
    public function authorize(IslandContext $context): void
    {
        if ($context->request->getHeaderLine('Interact') === 'true') {
            debug('authorized', false);
        }
    }

    /**
     * Inspect native writable and uploaded values after assignment.
     */
    public function updated(UpdatedPaths $paths): void
    {
        debug(['updated' => $paths->toArray()], false);
    }

    /**
     * Emit native formatted and plain dumps while retaining ordinary action behavior.
     */
    #[Action]
    public function inspect(string $mode = 'success'): void
    {
        debug(['value' => $this->value, 'password' => hash('sha256', $this->value)], true);
        \debug('global dump', false, false);
        echo '<script>window.debugExecuted = true</script><img src="/debug-external" onerror="alert(1)">';
        $this->value = 'Inspected';
        if ($mode === 'failure') {
            throw new RuntimeException('Private exception');
        }

        if ($mode === 'rejected') {
            throw new InvalidArgumentException('Rejected');
        }

        if ($mode === 'validation') {
            $this->setErrors(['value' => ['Invalid value.']]);
        }
    }

    /**
     * Keep diagnostics available when the action intentionally skips its template.
     */
    #[Action(render: false)]
    public function inspectOnly(): void
    {
        $this->inspect();
    }

    /**
     * Interleave diagnostics with immediately delivered application progress.
     */
    #[Action(stream: true)]
    public function inspectStream(string $mode = 'success'): void
    {
        debug('before progress', false);
        $this->stream('progress', 'Progress', replace: true);
        $this->streamEvent('debug-progress', ['step' => 1]);
        $this->inspect($mode);
    }

    /**
     * Verify diagnostics do not invalidate the strict redirect envelope.
     */
    #[Action]
    public function leave(): void
    {
        debug('before redirect', false);
        $this->redirect('/navigation', navigate: true);
    }
}
