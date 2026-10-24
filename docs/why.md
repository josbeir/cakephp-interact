---
title: 'Overview'
description: Use Alpine for browser behavior and Interact for PHP state, actions, and server-rendered islands.
---

<span id="why-interact"></span>
<span id="choose-the-right-boundary"></span>
<span id="keep-application-responsibilities-familiar"></span>
<span id="what-is-available-today"></span>

# Overview

CakePHP Interact connects Alpine to server-rendered PHP islands. Use Alpine for
browser behavior, and Interact when an interaction needs PHP state, an action,
or updated HTML. Each island updates without reloading its surrounding page.

Alpine is part of the runtime. Use its directives and expressions directly:
`x-data` for local state, `@click` for events, `:disabled` for bindings, and
`x-show` with `x-transition` for visibility. Interact adds the `$server` bridge
and `x-interact:*` directives for communication with PHP.

## Alpine and PHP responsibilities

| Need                                                    | Use                                                     |
| ------------------------------------------------------- | ------------------------------------------------------- |
| Open a panel, select a tab, or store a local preference | Alpine `x-data` and event handlers                      |
| Show, hide, animate, or style existing HTML             | Alpine `x-show`, `x-transition`, and attribute bindings |
| Read an island's declared PHP values in the browser     | Interact `$server.state` in an Alpine expression        |
| Invoke a PHP action or refresh server-rendered HTML     | Interact `$server.call()` or `$server.sync()`           |
| Bind an input to a writable PHP draft                   | Interact `x-interact:model`                             |
| Protect a form submission or schedule server refreshes  | Interact `x-interact:submit` or `x-interact:poll`       |

For example, `@click="expanded = !expanded"` changes local Alpine state without
a request. `@click="$server.call('save')"` is the same Alpine event syntax,
calling Interact to run a declared PHP action. The [Alpine and Interact](/guide/alpine)
primer combines both in one working example.

The standard Composer bundle includes Alpine; there is no separate Alpine setup.
If your application already starts Alpine, use the
[application-owned integration](/installation#application-owned-alpine).

See [How it works](/how-it-works) for flowcharts of page rendering and island updates.

## Features

- Input binding, live search, conditional fields, and URL-bound state.
- PHP actions, entity validation, explicit saving, redirects, and native downloads.
- Independent nested islands and browser events.
- Lazy and deferred loading, polling, and temporary file uploads.
- Opt-in page navigation and persistent layout regions.
- Third-party JavaScript widgets managed through Alpine.

Start with [Installation](/installation) and [Quickstart](/getting-started).
[Demo](/demo) links to the runnable application and its source. Use the documentation's
feature and API pages for syntax, defaults, and behavior.

## Application responsibilities

Use Table finders for queries and CakePHP validation, application rules, and
services for persistence. Current authorization and CSRF middleware apply to
updates. Synchronization does not save automatically.

Keep browser-only UI state in Alpine. Put interactive PHP values in declared
properties; entities, queries, and services remain on the server.

## Status and compatibility

Interact targets **PHP 8.5 and CakePHP 6 development builds**. It is available as
`dev-main`; there is no stable release. The API is experimental.

Normal links use CakePHP navigation. Opt in to enhanced page visits with
[`x-interact:navigate`](/directives/navigate). Entities, queries, and services
stay on the server; transport declared props and state instead. Form-plugin
adapters are not implemented.
