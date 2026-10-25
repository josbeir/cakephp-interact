---
title: 'HTML directives'
description: Look up Interact template directives and their supported options.
---

<span id="template-api"></span>
<span id="combine-bindings-in-a-form"></span>
<span id="use-ordinary-alpine-for-presentation"></span>

# HTML directives

Interact directives connect HTML controls and forms to a PHP island. Use them
inside an island created by `InteractHelper`.

Use Alpine directly for local UI behavior. `@click` listens for browser events,
`:disabled` and `:class` bind attributes, and `x-show` with `x-transition` controls
visibility. Interact adds server synchronization and rendering to these Alpine
templates. See [Alpine and Interact](/guide/alpine) for the ownership boundary.

| Directive                                 | Description                                                 |
| ----------------------------------------- | ----------------------------------------------------------- |
| [`x-interact:model`](/directives/model)   | Binds a form input to a PHP island property                 |
| [`x-interact:submit`](/directives/submit) | Handles form submission without a page reload               |
| [`x-interact:poll`](/directives/poll)     | Refreshes an island at a regular interval                   |
| [`x-interact:ignore`](/directives/ignore) | Preserves an element and its contents during server updates |

[`$server`](/reference/server) provides island state, actions, and request
feedback inside Alpine expressions. In `@click="$server.call('save')"`, Alpine
handles the event and Interact sends the PHP action request.

- [`x-interact:navigate`](/directives/navigate) visits ordinary CakePHP pages.
- [`x-interact:persist`](/directives/persist) retains explicit layout regions between visits.
- [`x-interact:asset`](/directives/asset) controls head asset reloads and persistent styles.

[`x-interact:stream`](/directives/stream) declares display targets for incremental action output.
