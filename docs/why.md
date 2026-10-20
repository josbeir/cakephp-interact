---
title: Why Interact?
description: See where PHP islands fit in a CakePHP application and which interactions Interact supports today.
---

# Why Interact?

A search box should update its results while you type. An article editor should
save without replacing the whole page. Interact lets you build those interactions
with a PHP class and an ordinary CakePHP element.

The first request renders HTML on the server. Later interactions send the
island's declared state and action to CakePHP, which authorizes the request and
renders new HTML for that region. Alpine preserves local browser interactions.

## Choose the right boundary

| You want to…                                         | Build…                                                     |
| ---------------------------------------------------- | ---------------------------------------------------------- |
| Filter a listing while typing                        | An island with a writable search term and a model finder   |
| Edit several records independently                   | A keyed editor island for each record                      |
| Recalculate choices when another field changes       | Writable fields, an `updated()` hook, and computed choices |
| Make most of an existing action template interactive | A whole-page island using that template                    |
| Toggle a local menu without contacting PHP           | Local Alpine state inside the island                       |

Start with one region. Your normal controller action and surrounding template
can keep rendering the rest of the page.

## Keep application responsibilities familiar

- **Templates:** native CakePHP templates and elements own the HTML.
- **Models:** named finders own domain queries; normal validation and rules still apply.
- **Saving:** an explicit action calls your application's persistence code.
- **Access:** your middleware and the island's `authorize()` check current permissions.

An island has its own declared variables. Pass a controller value as a prop when
mounting; load changing query results in `viewData()`. The
[state guide](/guide/state) shows both.

## What is available today?

The development preview includes input binding, PHP actions, validation feedback,
search and pagination, nested islands, browser events, and whole-page islands.
It uses its own fetch protocol, Alpine, and Alpine Morph, independently of Livewire
and htmx.

File uploads use explicit declarations, temporary staging, and normal CakePHP
validation; see [file uploads](/guide/uploads).

Links still use normal CakePHP navigation. Enhanced navigation and URL-bound state
are planned for phase 2. Automatic entity transport and a form
adapter are outside the current implementation. Public APIs may change before release.

[Build your first island](/getting-started) · [Try the demo](/examples)
