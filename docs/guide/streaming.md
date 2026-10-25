---
title: Streaming
description: Stream text, native elements, and JSON progress during a single island action.
---

# Streaming

Streaming displays incremental output while a declared action runs. Call the action
through `$server.call()` and mark it with `#[Action(stream: true)]`.

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Publishes report lines as they become available and retains the completed report.
 */
class ReportIsland extends Island
{
    #[State]
    public string $report = '';

    /**
     * Stream application results, then retain them for the final ordinary render.
     */
    #[Action(stream: true)]
    public function generate(): void
    {
        $this->report = '';
        $this->stream('report', '', replace: true);
        foreach (['First result', 'Second result'] as $line) {
            $this->report .= $line . "\n";
            $this->stream('report', $line . "\n");
        }
    }
}
```

```php [Template]
<?php
// templates/island/report.php
use function Cake\Core\h;
/** @var string $report */
?>
<button type="button" @click="$server.call('generate')" :disabled="$server.busy">
    Generate report
</button>
<pre x-interact:stream="report"><?= (string)h($report) ?></pre>
```

```php [Mount]
<?= $this->Interact->render('Report') ?>
```

:::

Complete [installation](/installation) first. See the tested
[report example](/demo#streaming) for text, elements, and progress together.

## stream()

`stream(string $to, string $content, bool $replace = false): void` appends literal
text to every matching [`x-interact:stream`](/directives/stream) target owned by
the current island. `replace: true` clears its previous content. HTML characters
remain text; missing targets are harmless.

Target and event names are lowercase identifiers matching
`[a-z][a-z0-9:-]*`. Names are literal values, rather than Alpine expressions.

## streamElement()

`streamElement(string $to, string $element, array $data = [], bool $replace = false): void`
renders a native CakePHP element through the island's configured view and helpers.
The element receives explicit `$data`. Unqualified element names use the island
class's plugin; `Plugin.element_name` selects another loaded plugin explicitly.

```php
$this->streamElement('rows', 'report_row', ['title' => $title]);
```

```html
<ul x-interact:stream.html="rows"></ul>
```

Escape application values in the element. The `.html` modifier explicitly accepts
HTML; it must match the message's mode. Custom renderers can implement
`Interact\Rendering\ElementRendererInterface` to support this method.

Targets and fragments must contain display markup. Controls, model bindings,
child island metadata, ignored regions, executable scripts, and nested stream
targets are rejected before the update changes the DOM. Keep interactive forms
and independently owned widgets outside the target. Alpine initializes appended
HTML once and cleans up replaced content.

## streamEvent()

`streamEvent(string $name, array $detail = []): void` immediately delivers a named
event to listeners in the island. Values must be JSON-compatible.

```php
$this->streamEvent('progress', ['percent' => 50]);
```

```html
<div
  x-data="{ percent: 0 }"
  x-interact:on:progress="percent = $event.detail.percent"
>
  <progress max="100" :value="percent" aria-label="Progress"></progress>
</div>
```

[`x-interact:on:event`](/directives/on) handles ownership, name matching, and
cleanup. `$event.detail` contains the payload; `$message` contains the original
`{ name, detail }` envelope. The same syntax receives [realtime notifications](/guide/realtime#listen-with-alpine).
Advanced observers can still listen to the bubbling `interact:stream` event from
the island root; its detail remains `{ name, detail }`. Ordinary
[`dispatch()`](/guide/events#dispatch) effects still wait for the successful
final result.

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

## Custom receiving adapters

An adapter connects an application-owned feed, such as Mercure or a WebSocket,
to an island's display targets. Map your provider's messages to
[`$server.receiveStream()`](/reference/server#receivestream); the normal action
POST keeps its built-in SSE reader.

For example, suppose an authorized SSE feed sends `{"text":"Next result\n"}`.
Register this external script [before Interact starts](/guide/alpine#custom-javascript):

<<< @/../tests/Fixture/browser/text-feed.js

Inside the receiving island, supply an authorized subscription URL as `$feedUrl`
through `viewData()` and attach the adapter:

```php
<?php
declare(strict_types=1);
use function Cake\Core\h;
/** @var string $feedUrl */
?>
<div x-data="textFeed" data-feed-url="<?= (string)h($feedUrl) ?>">
    <pre x-interact:stream="output"></pre>
</div>
```

Each message appends literal text to this island's `output` target immediately.
The adapter supplies the current island ID, catches malformed messages, and closes
its connection when Alpine destroys the wrapper. The connection stays outside
reactive state. Use your provider's client and cleanup method in the same lifecycle;
authentication, authorized topics, and reconnection belong to that integration.

The receiver also accepts `replace: true`, HTML for explicit `.html` targets, and
`type: 'event'` messages with `name` and JSON record `detail`. Event messages reach
[`x-interact:on:event`](/directives/on) listeners in the island. The raw
`interact:stream` event remains available to advanced observers. The npm package exports `StreamMessage`
and `IslandBridge` for typed adapters; see the [message contract](/reference/server#receivestream).

These are provisional display updates. Signed state, validation, request activity,
and action results stay with normal requests; a subsequent render is authoritative.
Invalid input throws to the adapter, while delivery after island disposal is ignored.

For managed topic subscriptions, shared provider connections, notification events,
and automatic island refreshes, see [realtime subscriptions](/guide/realtime).
