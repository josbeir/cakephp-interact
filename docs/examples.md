---
title: Explore the demo
description: Run the CakePHP demo and try live search, independent editors, conditional forms, and whole-page islands.
---

# Explore the demo

Try the interactions before integrating Interact into your application. The demo
is a bootable CakePHP application included in the repository; its files are also
used by integration and browser tests.

## Run it locally

You need PHP 8.5 with SQLite support, Composer, and Node 24.11+ or Node 26+.
From the repository checkout:

```sh
composer install
npm ci
npm run build
npm run preview
```

Open **http://127.0.0.1:8765**. Fixtures seed automatically.
The demo uses a test-only session identity; it is not a production authentication
example. Keep real credentials and private data out of it.

## Choose an example

| Open          | Try                                                                     | Follow the implementation                                                                                          |
| ------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `/`           | Search articles, edit two titles, save one, and keep the other draft    | [Forms and validation](/guide/forms), [nested islands](/guide/nesting)                                             |
| `/`           | Change country, choose a city, and confirm the selection                | [Dependent choices](/guide/forms#add-dependent-or-conditional-fields)                                              |
| `/playground` | Adjust independent counters with typed action arguments                 | [Actions and feedback](/guide/actions)                                                                             |
| `/playground` | Change seats and extras; reveal business fields and validate            | [Conditional fields](/guide/forms#add-dependent-or-conditional-fields)                                             |
| `/playground` | Add, toggle, and remove tasks while other local state survives          | [Task board source](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/TaskBoard.php) |
| `/playground` | Refresh the server clock; pause it or watch a simulated job complete    | [Polling](/guide/polling)                                                                                          |
| `/playground` | Update two colored panels; recreate the one whose DOM is protected      | [Morph protection](/guide/javascript-widgets#see-what-protection-changes)                                          |
| `/playground` | Search countries in a JavaScript widget; validate, reset, and remove it | [JavaScript widgets](/guide/javascript-widgets#try-an-enhanced-select)                                             |
| `/uploads`    | Stage documents, manage attachments, and validate before saving         | [File uploads](/guide/uploads)                                                                                     |
| `/search`     | Filter 1,200 resources; sort, change page size, and paginate            | [Search and pagination](/guide/search)                                                                             |
| `/page`       | Update a whole-page listing while its layout stays in place             | [Whole-page islands](/guide/page-islands)                                                                          |

The `/playground` **Failure recovery** card demonstrates the
[failure and recovery workflow](/guide/failures).

Playground state resets on reload. Article saves persist in the local fixture
database. Search filters are interaction state; they do not update the URL.

## Find the cooperating files

All demo files live under `tests/test_app/`:

| Directory                     | What it owns                                                     |
| ----------------------------- | ---------------------------------------------------------------- |
| `src/Controller/`             | Initial pages and attribute routes                               |
| `src/Island/`                 | Props, state, authorization, actions, and computed data          |
| `src/Model/Table/`            | Named finders, validation, and model configuration               |
| `templates/element/Interact/` | Embedded island markup                                           |
| `templates/Articles/page.php` | The whole-page island template                                   |
| `config/`                     | Deterministic fixture setup and demo-only identity configuration |

The guides import full implementations directly from these tested files so their
reference examples stay aligned with the application.

## Reset or troubleshoot

Stop the server and any running tests, then remove `build/tmp/demo.sqlite` to
reset fixture data. It is recreated on the next request.

`npm run preview` disables OPcache for the development server so template edits
appear immediately. Restart other development servers if cached PHP makes
browser behavior differ from CLI tests.

For asset loading, CSRF, and update errors, see [troubleshooting](/troubleshooting).
For the checks behind the examples, see [contributing](/contributing).
