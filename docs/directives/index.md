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

| Directive                                 | Description                                                 |
| ----------------------------------------- | ----------------------------------------------------------- |
| [`x-interact:model`](/directives/model)   | Binds a form input to a PHP island property                 |
| [`x-interact:submit`](/directives/submit) | Handles form submission without a page reload               |
| [`x-interact:poll`](/directives/poll)     | Refreshes an island at a regular interval                   |
| [`x-interact:ignore`](/directives/ignore) | Preserves an element and its contents during server updates |

Use ordinary Alpine `@click`, `:disabled`, `x-show`, and `:class` for events and
presentation. [`$server`](/reference/server) provides access to island state,
actions, and request feedback. See [Quickstart](/getting-started) for setup.

- [`x-interact:navigate`](/directives/navigate) visits ordinary CakePHP pages.
- [`x-interact:persist`](/directives/persist) retains explicit layout regions between visits.
