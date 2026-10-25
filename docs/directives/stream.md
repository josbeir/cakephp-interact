---
title: 'x-interact:stream'
description: Declare display targets for incremental action output.
---

# x-interact:stream

`x-interact:stream` declares a named, display-only target inside an island.
[Streaming actions](/guide/streaming) and [realtime display subscriptions](/guide/realtime#append-text)
append or replace its content.

```html
<pre x-interact:stream="answer"></pre>
```

Call `$this->stream('answer', $text)` from a `#[Action(stream: true)]` method.
Content is appended as literal text. Use `replace: true` in PHP to replace it.
Multiple matching targets in the owning island receive the same update; child
islands retain their own scope. Missing targets are ignored.

See the [complete report](/guide/streaming#stream-a-report-with-progress) for action
output or [published text](/guide/realtime#append-text) for updates from other
requests. Use [`x-interact:on:event`](/directives/on) for custom Alpine reactions.

## HTML elements

```html
<ul x-interact:stream.html="rows"></ul>
```

Use `streamElement('rows', 'row', ['item' => $item])` to render native HTML with
explicit variables. Escape application values inside the element. HTML and text
modes must match; Alpine initializes appended HTML and cleans up replaced content.

## Options and ownership

| Syntax                          | Content                                    |
| ------------------------------- | ------------------------------------------ |
| `x-interact:stream="name"`      | Literal text from `stream()`               |
| `x-interact:stream.html="name"` | Native element HTML from `streamElement()` |

The value is a literal lowercase identifier matching `[a-z][a-z0-9:-]*`, rather
than an Alpine expression. `.html` is the only modifier. The island root cannot
be a target.

Keep controls, child islands, ignored widget regions, executable scripts, runtime
metadata, and nested stream targets outside streamed content. Changes are
provisional: the final normal render is authoritative. See
[completion and failures](/guide/streaming#completion-validation-and-failure) and
[the complete report example](/demo#streaming).
