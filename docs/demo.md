---
title: Demo
description: Run the CakePHP Interact demo and explore its tested source.
---

# Demo

The demo in `tests/test_app/` is the runnable application used by Interact's HTTP
and browser tests. It combines the features documented in the guides and API pages.

## Run the demo

Use PHP 8.5 with SQLite support and Node 24.11+ or Node 26+.

```sh
composer install
npm ci
npm run build
composer preview
```

Open **http://127.0.0.1:8765**. Demo data seeds automatically. The session identity
is for demonstration; applications supply their own authentication.

## Realtime demo

With Docker available, run `npm run demo:realtime` instead of `composer preview`,
then open **http://127.0.0.1:8765/realtime** in two tabs. The launcher provisions an
isolated FrankenPHP container with PHP 8.5, CakePHP, and its built-in Mercure 1.x
hub on port 8765. The first run builds the image; later runs reuse Docker’s cache.
The launcher removes the container on exit and retains the demo database. Stop any existing preview on port 8765 first.

Share the working demo with `ngrok http 8765`. Browser subscriptions use
`/.well-known/mercure` on the current origin, so both the app and hub travel through
the same tunnel. PHP publishes through the internal address. No public URL setting
or second tunnel is needed.

The container invokes CakePHP's `server --frankenphp` command. The demo's
`ServerCommand` enables `--mercure` and disables SSE compression. This runs ordinary
PHP requests without worker mode. The image pins FrankenPHP 1.13.3 and installs
CakePHP's required Intl extension. `composer preview` remains the plain PHP preview.

<span id="realtime"></span>
The chat room stores messages in SQLite and refreshes an independent feed in every
subscribed tab. Type in one tab while sending from another: incoming messages arrive
without disturbing your draft. Your own sends also refresh the feed through the
normal response, so the chat remains usable without a live connection. Reloading
restores the latest 50 messages. The room is shared by everyone visiting the demo;
private notifications use separate user-specific topics.

The side panels demonstrate a private notification and a streamed chat recap with
progress, text logs, and an HTML result. These panels require the realtime launcher.

Tested source: [composer island](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/RealtimeChatIsland.php),
[feed island](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/RealtimeMessagesIsland.php),
[template](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/templates/island/realtime_chat.php).
See [realtime subscriptions](/guide/realtime) for configuration and delivery guarantees.

## Try the interactions

Paths in the source column are relative to
[`tests/test_app/`](https://github.com/josbeir/cakephp-interact/tree/main/tests/test_app).
Matching templates live under `templates/island/`, with page mounts under
`templates/`. Controllers use CakePHP routing attributes.

| Interaction                                                                                                                                         | Source                                                                                                                                                                                                                                               |
| --------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/`<br>Search articles, save one editor, preserve another draft, and choose dependent fields                                                        | [Listing](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/Article/ListingIsland.php), [dependent fields](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/DependentSelectsIsland.php) |
| <span id="forms"></span>`/forms`<br>Bind article and comment drafts, render validation errors, and explicitly save                                  | [Form island](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/Article/FormIsland.php), [Table](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Model/Table/FormArticlesTable.php)           |
| <span id="renderless-actions"></span>`/renderless`<br>Save drafts without replacing the view, with normal validation recovery                       | [Draft editor](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/DraftEditorIsland.php)                                                                                                                                |
| <span id="widgets"></span><span id="polling"></span>`/playground`<br>Try counters, conditional forms, tasks, polling, widgets, and failure recovery | [Page mounts](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/templates/Articles/playground.php), [widget wrapper](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/resources/ts/enhanced-select.ts)        |
| <span id="search"></span>`/search`<br>Filter 1,200 resources, sort, paginate, and restore URL state                                                 | [Search island](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/ResourceSearchIsland.php), [Table finders](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Model/Table/ResourcesTable.php)  |
| <span id="child-inputs"></span>`/composition`<br>Bind reusable child inputs and send reactive parent props                                          | [Parent](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/Composition/OrderIsland.php), [picker](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/Composition/PickerIsland.php)        |
| <span id="uploads"></span>`/uploads`<br>Stage documents, manage attachments, validate, and save                                                     | [Upload island](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/DocumentUploadIsland.php), [application Form](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Form/DocumentForm.php)        |
| <span id="lazy-loading"></span>`/loading`<br>Explore loading modes, custom placeholders, and nested preservation                                    | [Page mounts](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/templates/Loading/index.php)                                                                                                                                      |
| <span id="navigation"></span>`/navigation`<br>Navigate ordinary CakePHP routes while preserving a layout notebook                                   | [Controller](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Controller/NavigationController.php), [page](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/templates/Navigation/index.php)              |
| <span id="redirects-and-downloads"></span>`/outcomes`<br>Save and redirect, request an authorized download, and navigate explicitly                 | [Actions](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/ActionOutcomesIsland.php), [controller](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Controller/OutcomesController.php)        |
| <span id="streaming"></span>`/streaming`<br>Stream text, native elements, and progress during one action                                            | [Report island](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/StreamingReportIsland.php), [template](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/templates/island/streaming_report.php)   |
| `/page`<br>Update a whole-page island while its layout stays in place                                                                               | [Controller](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Controller/ArticlesController.php)                                                                                                                             |

## Debugging

Open `/debugging` from the demo navigation. Edit the message and try **Dump draft**,
**Stream three dumps**, or **Dump without rendering**. Clear the message before
**Dump and validate** to receive validation feedback alongside the dump.
**Dump then fail** deliberately throws after dumping; **Resume after failure**
restores normal requests.

The tested example uses [DebuggingIsland](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/DebuggingIsland.php)
and its [template](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/templates/island/debugging.php).
See [debugging](/guide/debugging) for viewer settings and DebugKit coexistence.

## Navigation head and CSP examples

Open `/navigation` and switch between Overview and Details. Each page has its own external stylesheet; the previous page's sheet is removed. A shared inline style uses `x-interact:asset="persist"` with a stable id and remains when absent from Details.

Follow **Try rotating CSP nonces** to `/navigation/nonces`. Each GET generates distinct script/style nonces. Switch pages and use Back/Forward: the nonce-authorized heading color changes while enhanced navigation retains the original browser policy. Leaving this example changes the policy and uses a native visit.

Tested source: [nonce page](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/templates/Navigation/nonces.php), [demo security middleware](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Middleware/DemoSecurityMiddleware.php).

## Source and reset

The demo uses the `TestApp` namespace. Adapt it to your application's namespace
when reusing its source. Props, drafts, authorization, validation, and saving
remain explicit in each interaction.

Article saves persist in `build/tmp/demo.sqlite`. To reset the data, stop the
server and tests, then delete that file. It is recreated on the next request.
`composer preview` disables OPcache so edits appear immediately.

See [Contributing](/contributing) for test commands and [Troubleshooting](/troubleshooting)
for asset or update failures.
