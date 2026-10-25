---
title: Streaming
description: Build a report that displays text and progress while one island action runs.
---

# Streaming

Streaming displays text, HTML, or progress while a single island action runs.
It uses that action's HTTP response and **does not require a realtime provider**.
Use [realtime subscriptions](/guide/realtime) when other requests or jobs need to
publish updates to connected tabs.

## Stream a report with progress

Complete [installation](/installation). Create `src/Island/ReportIsland.php` and
`templates/island/report.php`, then mount the island in an existing page:

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Streams sample report lines and retains them for the final PHP render.
 */
class ReportIsland extends Island
{
    #[State]
    public string $report = '';

    /**
     * Emit each result and its progress as the sample work completes.
     */
    #[Action(stream: true)]
    public function generate(): void
    {
        $this->report = '';
        $this->stream('report', '', replace: true);
        $this->streamEvent('progress', ['percent' => 0]);
        $steps = ['Collecting data', 'Building totals', 'Report complete'];
        foreach ($steps as $index => $line) {
            // Demo pacing only; real work should emit results as they become available.
            usleep(400_000);
            $this->report .= $line . "\n";
            $this->stream('report', $line . "\n");
            $this->streamEvent('progress', [
                'percent' => (int)round(($index + 1) / count($steps) * 100),
            ]);
        }
    }
}
```

```php [Template]
<?php
declare(strict_types=1);
use function Cake\Core\h;
/**
 * @var \Interact\View\TemplateView $this
 * @var string $report
 */
?>
<div
    x-data="{ percent: 0 }"
    x-interact:on:progress="percent = $event.detail.percent"
>
    <?= $this->Form->button('Generate report', [
        'type' => 'button',
        '@click' => '$server.call("generate")',
        ':disabled' => '$server.pending("generate")',
    ]) ?>
    <progress max="100" :value="percent" aria-label="Report progress"></progress>
    <pre x-interact:stream="report"><?= h($report) ?></pre>
</div>
```

```php [Mount]
<?= $this->Interact->render('Report') ?>
```

:::

Click **Generate report**. Lines appear one at a time while the button is disabled,
and progress reaches 100%. The completed text remains after the action's final
render. Click again to reset the output and run a new report.

The delay only makes this demonstration visible. In application code, stream each
result when your actual work produces it. There is no separate JavaScript entry point.

### How the pieces match

| PHP                                          | Template                                                   | Result                                         |
| -------------------------------------------- | ---------------------------------------------------------- | ---------------------------------------------- |
| `#[Action(stream: true)]`                    | `$server.call('generate')`                                 | Run an action that can emit incremental output |
| `stream('report', $text)`                    | `x-interact:stream="report"`                               | Append literal text to the `report` target     |
| `streamEvent('progress', ['percent' => 50])` | `x-interact:on:progress="percent = $event.detail.percent"` | Update local Alpine progress                   |
| `#[State] public string $report`             | `<?= h($report) ?>`                                        | Retain completed text through the final render |

Streamed DOM output is provisional. Assigning `$this->report` keeps the final
PHP-rendered output consistent; it does not save the report to a database or make
it survive a page reload. Use application storage for that.

## stream()

`stream($to, $content, replace: false)` appends literal text. The first argument
matches a named [`x-interact:stream`](/directives/stream) target in the same island.
The report action uses `replace: true` once to clear a previous run.

HTML characters remain text. Multiple matching targets in the island receive the
same content; missing targets are ignored and child islands retain their own scope.
Target and event names match `[a-z][a-z0-9:-]*`.

## streamElement()

Use `streamElement()` when the output is a native CakePHP element. This standalone
summary example keeps its result in state so it remains visible after the final
render. Create `src/Island/SummaryIsland.php`, `templates/island/summary.php`, and
`templates/element/report_summary.php`:

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Streams a native summary element and retains its value for the final render.
 */
class SummaryIsland extends Island
{
    #[State]
    public string $result = '';

    /**
     * Render an application result into the summary target.
     */
    #[Action(stream: true)]
    public function summarize(): void
    {
        $this->result = 'Report ready.';
        $this->streamElement('summary', 'report_summary', [
            'result' => $this->result,
        ], replace: true);
    }
}
```

```php [Element]
<?php
declare(strict_types=1);
use function Cake\Core\h;
/** @var string $result */
?>
<p><strong><?= h($result) ?></strong></p>
```

```php [Template]
<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var string $result
 */
?>
<div>
    <?= $this->Form->button('Show summary', [
        'type' => 'button',
        '@click' => '$server.call("summarize")',
        ':disabled' => '$server.pending("summarize")',
    ]) ?>
    <div x-interact:stream.html="summary">
        <?= $this->element('report_summary', ['result' => $result]) ?>
    </div>
</div>
```

```php [Mount]
<?= $this->Interact->render('Summary') ?>
```

:::

Click **Show summary** to render the formatted result. `summary` is the target;
`report_summary` is the element name. `.html` is required for element output.
The declared `result` state lets the template reproduce the summary after the
final render. Escape application values inside elements normally.

`streamElement($to, $element, $data, replace: false)` supplies explicit element
variables and uses the island's view and helpers. Unqualified names resolve in the
island's plugin; `Plugin.element_name` selects another loaded plugin.

Keep targets display-only: put controls, nested islands, and widget-owned markup
outside them. See [`x-interact:stream`](/directives/stream#options-and-ownership)
for target restrictions.

## streamEvent()

`streamEvent($name, $detail)` delivers a named event immediately. The report's
`progress` event carries `percent` in `$event.detail`; its listener needs no
ownership checks or window binding. Use [`x-interact:on`](/directives/on) for
expression syntax, optional metadata, and ownership rules.

The same listener receives [realtime notifications](/guide/realtime#send-a-notification-to-two-tabs).
Ordinary [`dispatch()`](/guide/events#dispatch) events wait for the accepted final
result instead of arriving during the action.

## Completion, validation, and failure

Streaming methods are available only inside an executing action declared with
`stream: true`. Calling them from `mount()`, `updated()`, rendering, or an ordinary
action throws `LogicException`.

Incremental output is provisional. The final normal render replaces it with the
authoritative template output, so retain completed results in declared state or
application storage when they should survive rendering. Streaming itself never
changes signed state or saves records.

`#[Action(stream: true, render: false)]` keeps the streamed DOM and accepts the
usual signed state and response effects. Validation errors still render, including
the normal subsequent render that clears errors. Redirects and downloads retain
their existing contracts.

`busy`, `pending()`, `loading()`, submit protection, and the action promise remain
active through completion. Other actions stay queued. Newer local drafts survive
the final render. Removed islands and superseded page visits ignore late chunks.

An action exception leaves already displayed output visible and publishes the
usual `$server.error` and `interact:error`. Missing terminal results, malformed
frames, and disconnected streams fail the request. Interact never reconnects or
automatically replays an action. Aborting a reader does not cancel server work;
check durable application results before an explicit retry.

## HTTP and deployment

If all output appears at once, check PHP, web-server, compression, and proxy
buffering. Interact flushes each frame, but infrastructure must allow immediate
delivery. A long streaming action occupies a PHP worker until it finishes.

::: details Response lifecycle and protocol limits

The existing CSRF-protected POST endpoint negotiates JSON or `text/event-stream`.
Only server-declared streaming actions select SSE. The browser uses native fetch
and reads standard UTF-8 SSE frames from that POST response. Ordinary actions and
synchronizations retain JSON responses. A JSON-only client receives the final
result without incremental delivery.

Snapshots, arguments, writable edits, uploads, and current authorization are checked
before the stream opens. Hooks and action execution continue during native response
body emission, after middleware selects headers. The stream ends with exactly one
normal result or safe failure frame. Its terminal status carries validation (422)
or failure semantics because HTTP headers have already been sent as 200. Unexpected
exceptions use CakePHP's configured exception logger.

The session binding is captured before emission and the native session is committed
and closed before the action runs. This allows other requests to use that session;
final signing does not reopen it. Complete session, cookie, and header changes
before emission. Streaming actions must use the streaming methods rather than echo,
`exit`, output buffers, or manual header changes.

Disable response buffering for this endpoint in PHP, compression middleware, your
web server, and proxies. Interact flushes each frame and sends
`X-Accel-Buffering: no`; infrastructure can still buffer it. Middleware must pass
through the callback body without consuming or replacing it. Long actions occupy a
PHP worker; choose suitable worker capacity and request timeouts. This finite
request has no heartbeat, replay, or resumable delivery guarantee.

Each message's encoded JSON data is limited to 1,048,576 UTF-8 bytes (1 MiB), including the complete
terminal payload. PHP checks the serialized JSON before emitting a frame; the
browser checks the decoded SSE data before applying it. SSE field prefixes and
line endings are outside this data limit. Keep individual messages and the final
view, state, and snapshot within it. Oversized server output is logged and becomes
a safe terminal failure, without emitting the oversized frame.

The browser uses `eventsource-parser` for framing, with bounded buffering for
incomplete messages. Unknown SSE fields and comments are ignored; invalid UTF-8,
oversized message data, and a missing terminal result still fail the request.

:::

## Custom receiving adapters

Action streaming already includes its HTTP reader. For an external provider,
use the [provider adapters](/guide/realtime-providers). Applications that own a
separate per-island feed can use the advanced [direct display adapter](/guide/realtime-providers#direct-display-adapters)
example; its connection lifecycle belongs to the application.

The [complete report demo](/demo#streaming) combines text, elements, and progress
and links the tested source.
