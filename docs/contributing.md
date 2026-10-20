# Contributing

Read the root `AGENTS.md` before changing code. Use PHP 8.5, CakePHP 6 development builds, and Node 24 or newer.

```sh
composer install
npm ci
npm run build
npm run preview
```

The demo runs at `http://127.0.0.1:8765`. It uses deterministic SQLite fixtures in `build/tmp/demo.sqlite`. Remove that fixture database to reset the demo when no tests/server are running. The role-changing endpoint exists only in this test application.

`npm run preview` disables OPcache for the development server so template edits appear immediately. Restart an already running preview server after changing this command. If another PHP server serves the demo, configure its development cache to revalidate files immediately or restart it after edits; fresh CLI test processes can otherwise run newer code than a cached web request.

The demo uses its own `interact_demo_csrf` cookie because applications on the same hostname share cookies across ports. CSRF protection remains enabled on updates. CakePHP's error middleware renders rejected requests with an HTTP error status rather than allowing a PHP fatal document to appear as a successful response.

The demo has four pages:

| Page          | Examples                                                                                                                                                                                                 |
| ------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/`           | Debounced search, independent article drafts, explicit saves, parent refresh events, and model-backed dependent city choices                                                                             |
| `/playground` | Independent counters with typed action arguments; a workshop quote with numeric inputs, checkbox groups, conditional company fields, and server validation; a task collection with stable row identities |
| `/search`     | A library of 1,200 seeded resources with debounced multiword search, topic and difficulty filters, stable sorting, page sizes, and bounded pagination                                                    |
| `/page`       | A whole-page island that reuses the controller action template and preserves its surrounding layout                                                                                                      |

Playground examples are transient and reset on reload. Article saves use the SQLite database. The demo stylesheet is local and requires no external assets or build step.

The search library seeds automatically into existing demo databases. Its Table finders query titles, descriptions, and topic names; every search word must match one of those fields. Matching is case-insensitive for the fixture's ASCII text and treats SQL wildcard characters literally. SQLite substring functions keep values bound through CakePHP expressions. Search choices travel in the island snapshot; the current result rows and counts are queried on the server and never serialized as state. This demo does not implement URL-bound search state.

## PHP checks

```sh
composer validate --strict
composer check
composer test-coverage
```

`check` runs PHPUnit, Mago analysis, Mago lint, Rector dry run, and formatting verification. `rector-fix` applies refactorings and then Mago formatting. Preserve the approved skips rather than weakening CakePHP Mago rules.

Coverage needs Xdebug coverage mode or PCOV. With a compatible Xdebug build, `composer test-coverage-branches` also writes method, line, branch, and path coverage to `build/php-branches.txt`. PHP 8.5.11 with Xdebug 3.5.3 currently crashes during branch/path instrumentation in this development environment; ordinary line coverage works. Branch reporting remains optional until that toolchain issue is resolved. Include unexecuted source and enforce at least 95% unrounded line coverage. Missing reports and empty executable-source measurements fail.

## Browser runtime

```sh
npm run check
npm run test:coverage
npx playwright install --with-deps chromium firefox webkit
npm run test:browser
```

Vitest tests transport boundaries, scheduling, controls, draft reconciliation, and Alpine/Morph integration. Playwright uses the real CakePHP demo. PHP integration tests run the middleware stack in process without an HTTP server.

Runtime code lives in focused ES modules under `resources/ts`: `bindings/` owns native controls, `scheduling/` serializes requests, `state/` validates and reconciles drafts, `transport/` validates HTTP envelopes, and `dom/` owns morphing and selection. `client.ts` coordinates those modules; `index.ts` installs the Alpine integration.

Use Table/Behavior finders for demo domain queries and `LocatorAwareTrait::fetchTable()` for demo Table access. Keep the plugin core's service dependencies injectable.

Demo controller routes use `Get`/`Post` attributes discovered by `connectAttributes()`. The plugin's update route remains configurable through normal scoped routing. The demo sends a CSP that allows same-origin scripts without `unsafe-eval` or inline scripts.

Every PHP/TypeScript class, including tests and demo classes, needs a concise docblock describing its responsibility. Do not weaken assertions, suppress rules, exclude production code, or add coverage-ignore annotations to meet a numerical goal.

## Documentation and releases

```sh
npm run docs:build
npm run package:composer
npm pack --dry-run
```

The docs use the `/cakephp-interact/` Pages base and are published at [josbeir.github.io/cakephp-interact](https://josbeir.github.io/cakephp-interact/). The Documentation workflow builds documentation changes on pull requests and pushes to `main`. Only `main` can deploy to the `github-pages` environment; pull requests build without deployment permissions. A manual workflow run can also publish `main`. Repository Pages settings must use **GitHub Actions** as the source.

Package builds supply an npm module with declarations and a standalone `webroot/js/runtime.js` for Composer users. The `package:composer` command builds a reproducible archive under `build/releases/` with PHP source, the standalone bundle, and third-party licenses. Source checkouts require a browser build. Publishing to Packagist must use a distribution containing those generated assets; a default GitHub source archive does not contain them. The Quality workflow validates packages but does not publish them.

Report actual checks and unavailable tooling. Do not commit installed dependencies, reports, caches, credentials, or temporary outputs.
