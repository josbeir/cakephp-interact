---
title: 'Overview'
description: CakePHP Interact provides server-rendered reactive PHP islands.
---

<span id="why-interact"></span>
<span id="choose-the-right-boundary"></span>
<span id="keep-application-responsibilities-familiar"></span>
<span id="what-is-available-today"></span>

# Overview

CakePHP Interact adds interactive PHP regions to CakePHP pages. An island renders
HTML and handles subsequent actions without reloading the surrounding page.

A PHP class declares its state and actions. CakePHP templates produce the HTML;
Alpine handles browser interactions. Interact is independent of Livewire and htmx.

## Features

- Input binding, live search, and conditional fields.
- PHP actions, entity validation, and explicit saving.
- Independent nested islands and browser events.
- Polling and temporary file uploads.
- Alpine integration and third-party JavaScript widgets.

Start with [Installation](/installation) and [Quickstart](/getting-started).
[Examples](/examples) contains complete applications. Use the documentation's
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

Navigation, URL-bound state, entity transport, and form-plugin adapters are not
implemented. Normal links continue to use CakePHP navigation.
