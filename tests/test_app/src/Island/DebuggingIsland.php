<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Validation\Validator;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use RuntimeException;
use function Cake\Error\debug;

/**
 * Demonstrates native CakePHP dumps across successful, streamed, and failed updates.
 *
 * The message is an editable draft; inspection never saves it. Application validation
 * and an intentional exception make their response behavior visible beside the dumps.
 */
class DebuggingIsland extends Island
{
    #[State(writable: true)]
    public string $message = 'Hello from the island';

    #[State]
    public int $inspections = 0;

    #[State]
    public string $status = 'Ready to inspect';

    #[State]
    public string $progress = 'No streamed output yet.';

    /**
     * Dump the current draft and demonstrate distinct scalar values through normal JSON.
     */
    #[Action]
    public function inspect(): void
    {
        $this->inspections++;
        debug([
            'message' => $this->message,
            'inspection' => $this->inspections,
            'values' => ['null' => null, 'false' => false, 'zero' => 0, 'empty' => ''],
        ]);
        $this->status = 'JSON dump captured';
    }

    /**
     * Update accepted state and diagnostics while retaining the existing template.
     */
    #[Action(render: false)]
    public function inspectOnly(): void
    {
        $this->inspect();
        $this->status = 'Dump captured without rendering';
    }

    /**
     * Produce three dumps alongside immediately visible application progress.
     */
    #[Action(stream: true)]
    public function inspectStream(): void
    {
        $this->inspections++;
        $this->progress = '';
        foreach (range(1, 3) as $step) {
            debug(['step' => $step, 'message' => $this->message]);
            $line = sprintf("Step %d: captured the current message.\n", $step);
            $this->progress .= $line;
            $this->stream('progress', $line, replace: $step === 1);
            // Demo pacing makes incremental delivery visible without external services.
            usleep(250_000);
        }

        $this->status = 'Streaming dumps captured';
    }

    /**
     * Dump before application validation so rejected drafts remain inspectable.
     */
    #[Action]
    public function inspectValidation(): void
    {
        debug(['message' => $this->message, 'phase' => 'before validation']);
        $validator = new Validator()->notEmptyString('message', 'Enter a message before validating.');
        $errors = $validator->validate(['message' => $this->message]);
        $this->setErrors($errors);
        $this->status = $errors === [] ? 'Message is valid' : 'Validation rejected the empty message';
    }

    /**
     * Demonstrate that dumps survive a failure while CakePHP keeps exception logging.
     */
    #[Action]
    public function inspectFailure(): void
    {
        debug(['message' => $this->message, 'phase' => 'before intentional failure']);

        throw new RuntimeException('Intentional debugging demo failure.');
    }
}
