---
title: 'Examples'
description: Complete CakePHP examples for forms, search, uploads, polling, and JavaScript widgets.
---

<span id="explore-the-demo"></span>

# Examples

These examples include the PHP classes, templates, data setup, and mount calls
needed for each interaction. Complete [installation](/installation) first.

| Example                                        | Demonstrates                                          |
| ---------------------------------------------- | ----------------------------------------------------- |
| [Article form](/examples/article-form)         | Entity validation and saving                          |
| [Search and pagination](/examples/search)      | Table finders and bounded result pages                |
| [Dependent fields](/examples/dependent-fields) | Server-derived choices and membership validation      |
| [Single document upload](/examples/upload)     | File staging, validation, and private storage         |
| [Job polling](/examples/polling)               | Pause/resume and completion controlled by PHP         |
| [Enhanced select](/examples/enhanced-select)   | Modelable inputs, protected DOM, and PHP resets       |
| [Chart updates](/examples/chart)               | Watching server state and updating a library instance |

<span id="run-it-locally"></span>

## Run the demo

The repository includes a separate playground with additional variations:

```sh
composer install
npm ci
npm run build
npm run preview
```

Use PHP 8.5 with SQLite support and Node 24.11+ or Node 26+. Open
**http://127.0.0.1:8765**. Fixtures seed automatically. The demo uses a test-only
session identity; applications supply their own authentication.

<span id="choose-an-example"></span>

## Demo catalog

| Open          | Try                                                                     | Follow the implementation                                                                                          |
| ------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `/`           | Search articles, edit two titles, save one, and keep the other draft    | [Article form](/examples/article-form), [nested islands](/guide/nesting)                                           |
| `/`           | Change country, choose a city, and confirm the selection                | [Dependent fields](/examples/dependent-fields)                                                                     |
| `/playground` | Adjust independent counters with typed action arguments                 | [Actions and feedback](/guide/actions)                                                                             |
| `/playground` | Change seats and extras; reveal business fields and validate            | [Conditional fields](/guide/forms#dependent-and-conditional-fields)                                                |
| `/playground` | Add, toggle, and remove tasks while other local state survives          | [Task board source](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/TaskBoard.php) |
| `/playground` | Refresh the server clock; pause it or watch a simulated job complete    | [Polling](/directives/poll)                                                                                        |
| `/playground` | Update two colored panels; recreate the one whose DOM is protected      | [Morph protection](/directives/ignore)                                                                             |
| `/playground` | Search countries in a JavaScript widget; validate, reset, and remove it | [JavaScript widgets](/examples/enhanced-select)                                                                    |
| `/uploads`    | Stage documents, manage attachments, and validate before saving         | [File uploads](/guide/uploads)                                                                                     |
| `/search`     | Filter 1,200 resources; sort, change page size, and paginate            | [Search and pagination](/examples/search)                                                                          |
| `/page`       | Update a whole-page listing while its layout stays in place             | [Page islands](/guide/page-islands)                                                                                |

The `/playground` **Failure recovery** card demonstrates the
[failure and recovery workflow](/guide/failures).

Playground state resets on reload. Article saves persist in the local fixture
database. Search filters are reflected in the URL. The page number uses browser history;
Back and Forward preserve unrelated reading notes.

<span id="find-the-cooperating-files"></span>
<span id="reset-or-troubleshoot"></span>

## Source and reset

Focused documentation examples live in `tests/docs/app/`. The broader demo lives
in `tests/test_app/` and shares its source with integration and browser tests.

To reset demo data, stop the server and tests, then delete `build/tmp/demo.sqlite`.
It is recreated on the next request. `npm run preview` disables OPcache so edits
appear immediately. See [Troubleshooting](/troubleshooting) for asset or update
failures and [Contributing](/contributing) for quality checks.

## Action outcomes

[Save and redirect](/examples/redirect) follows model validation with native navigation.
[Invoice download](/examples/download) serves a file from a separate CakePHP endpoint.
The larger demo combines both at `/outcomes`.
