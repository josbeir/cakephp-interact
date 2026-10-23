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
[report example](/examples/streaming) for text, elements, and progress together.

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

`streamEvent(string $name, array $detail = []): void` immediately dispatches the
bubbling `interact:stream` DOM event from the island root. Its detail is
`{ name, detail }`; values must be JSON-compatible.

```php
$this->streamEvent('progress', ['percent' => 50]);
```

```html
<div
  x-data="{ percent: 0 }"
  @interact:stream.window="$event.target === $el.closest('[data-interact-id]') && (percent = $event.detail.detail.percent)"
>
  <progress max="100" :value="percent" aria-label="Progress"></progress>
</div>
```

The event bubbles from the island root. A listener inside that root uses `.window`
and filters the event target to its own island, as above. An ancestor outside the
island can listen directly. Use the event name to distinguish progress messages. Ordinary
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
request has no heartbeat, replay, or resumable delivery guarantee. The reader limits
each SSE line and accumulated data frame to 1,048,576 UTF-16 code units, including
the terminal payload. Keep individual chunks and the final view within that limit.

## Custom receiving adapters

[`$server.receiveStream(message)`](/reference/server#receivestream) accepts normalized,
versioned, island-scoped display messages independently of SSE. An application can
translate its own transport's events to these messages, including events from
Mercure or a WebSocket client. Import `StreamMessage` and `IslandBridge` from the
npm package for TypeScript integration.

```ts
import type { IslandBridge, StreamMessage } from '@josbeir/cakephp-interact';

export function displayProgress(
  server: IslandBridge,
  islandId: string,
  percent: number,
): void {
  const message: StreamMessage = {
    version: 1,
    id: islandId,
    type: 'event',
    name: 'progress',
    detail: { percent },
  };
  server.receiveStream(message);
}
```

The receiver validates unknown input, enforces island identity and target ownership,
and accepts text/HTML targets or progress events. It cannot accept signed state,
terminal action results, or execute PHP actions. Invalid messages throw; delivery
after island disposal is ignored. Applications own adapter connection lifetime,
authentication, subscription authorization, routing, reconnection, and cleanup.
No push provider or subscription client is required by action streaming.
