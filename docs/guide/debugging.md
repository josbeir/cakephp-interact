---
title: Debugging
description: Use CakePHP debug() during JSON and streaming island updates without corrupting responses.
---

# Debugging

Interact captures CakePHP `debug()` output during island updates and displays it
outside the island in a development viewer. Capture defaults to the application's
`debug` mode. No additional debugging function or browser setup is required.

```php
use Interact\Attribute\Action;
use function Cake\Error\debug;

// Inside an island class:
#[Action]
public function inspect(): void
{
    debug($this->data);
}
```

Here, `data` is an application-declared island property. The global `debug()`
alias works too when enabled by the application. CakePHP retains its formatting,
source locations, output masks, and return value.

Try `/debugging` in the [demo application](/demo#debugging): edit the message,
inspect JSON and streaming dumps, validate an empty draft, or trigger an
intentional failure after a dump.

## JSON and streaming responses

Capture surrounds island preparation and execution, including lifecycle hooks,
initialization of pending islands, uploads, and renderless actions. The normal
update still applies. Validation responses and failures also retain output
produced before the failure; exception logging remains CakePHP's responsibility.

For JSON, captured output appears in the response's optional `diagnostics` field.
For SSE, it appears in separate `diagnostic` frames before subsequent progress or
terminal frames. Progress still flushes immediately. Diagnostic frames do not
become application `interact:stream` events.

The viewer shows the latest diagnostic request, combining that request's stream
chunks. New output does not take application focus. Use **Hide** to collapse it,
**Show** to expand it, and **Clear** to release its contents. **Copy** copies
readable dump text when the browser allows clipboard access. The viewer follows
the application's color scheme. Enhanced navigation clears the viewer too.

Output is displayed as inert text inside a sandboxed frame. CakePHP's dump scripts
and arbitrary captured HTML do not execute. The viewer works with both the
Composer bundle and the npm Alpine integration, including strict script and style
CSP policies that allow the runtime and its sandboxed frame.

## Viewer appearance

Set CSS custom properties on `:root` or `body` in your application stylesheet.
They inherit into the viewer's shadow DOM; no JavaScript configuration is needed.

```css
:root {
  --interact-debug-width: 40rem;
  --interact-debug-height: 18rem;
  --interact-debug-background: #18202f;
  --interact-debug-color: #e8edf5;
  --interact-debug-border-color: #3c4960;
  --interact-debug-accent-color: #a8c7ff;
  --interact-debug-radius: 10px;
  --interact-debug-font-family: system-ui, sans-serif;
  --interact-debug-z-index: 10000;
  --interact-debug-color-scheme: dark;
}
```

All properties are optional. `width` controls the drawer's width, capped to fit the
viewport; `height` controls its output area, within the drawer's 60vh maximum.
The color, radius, font, and stacking properties style the drawer and its controls.

The sandboxed dump document does not inherit application styles. Use
`--interact-debug-color-scheme` (`light`, `dark`, or `light dark`) to select its
native output theme on the next dump. Omitting it follows the application's
color scheme. Other viewer properties do not restyle the dump document itself.

## Configuration

Disable only the viewer to inspect dumps in the browser's Network panel:

```php
// config/app_local.php — configuration excerpt
'Interact' => [
    'debug' => ['viewer' => false],
],
```

Disable Interact's capture entirely:

```php
'Interact' => [
    'debug' => ['enabled' => false],
],
```

| Setting         | Default                  |
| --------------- | ------------------------ |
| `debug.enabled` | Application `debug` mode |
| `debug.viewer`  | `true`                   |

`debug.enabled` controls capture; explicit `true` cannot override application
debugging being off. `debug.viewer` controls the built-in viewer while capture
is enabled.

With capture disabled, output follows native PHP behavior: direct output from an
island class can invalidate a JSON or SSE response. Neither option changes
CakePHP's global debugging settings. When application debugging is off, Interact
adds no diagnostic fields or frames.

## Templates and initial pages

`debug()` inside an island template or element stays in that template's HTML,
including after an update. Initial document rendering also retains native CakePHP
behavior. Only direct output outside native template buffers is captured during
asynchronous island execution.

```php
<?php debug($data); ?>
```

Template variables must come from declared props, state, or `viewData()`; hosting
controller variables are not implicitly available. See [state](/guide/state).

## DebugKit and logs

DebugKit remains optional. Setting `debug.viewer` to `false` lets applications use
its normal request history, query, and log panels while keeping captured dumps in
Network responses. Interact does not add a DebugKit panel or store dumps there.

DebugKit identifies API requests with the `X-DEBUGKIT-ID` response header; its
[API request documentation](https://book.cakephp.org/debugkit/5/api-requests.html)
explains how to open the associated toolbar data.

For persistent logging, use CakePHP's existing API:

```php
use Cake\Error\Debugger;

Debugger::log($this->data);
```

## Limits

- `dd()`, `die()`, and `exit()` terminate PHP before normal response completion.
  This feature does not support displaying them as completed diagnostic updates.
  Xdebug breakpoints work independently of the response transport.
- Capture includes direct `echo`, `print_r()`, and similar buffered output. It
  cannot distinguish those bytes from dumps. CakePHP masks its own dumps;
  Interact cannot apply those masks to arbitrary output.
- Retention is limited to 256 KiB per request. Excess output is discarded and
  truncation is shown. Invalid UTF-8 is replaced before JSON encoding. Streaming
  diagnostics are split to respect the existing frame-size limit.
- Application code must not close Interact's output buffer or leave its own
  buffer open while streaming. Output written directly to a file descriptor
  bypasses PHP output buffering. Output from unrelated middleware is outside
  the capture boundary.
- The browser retains only the latest diagnostic request in memory. No dump
  history, telemetry, or automatic retry is added. Debug output may contain
  application data; keep application debug mode limited to development.

See [request lifecycle](/reference/request-lifecycle) for timing observations
without response data, and [failure handling](/guide/failures) for application UI.
