# Contributing

Read the root `AGENTS.md` before changing code. Use PHP 8.5, CakePHP 6 development builds, and Node 24 or newer.

```sh
composer install
npm ci
npm run build
npm run preview
```

The demo runs at `http://127.0.0.1:8765`. It uses deterministic SQLite fixtures in `build/tmp/demo.sqlite`. Remove that fixture database to reset the demo when no tests/server are running. The role-changing endpoint exists only in this test application.

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

Use Table/Behavior finders for demo domain queries and `LocatorAwareTrait::fetchTable()` for demo Table access. Keep the plugin core's service dependencies injectable.

Demo controller routes use `Get`/`Post` attributes discovered by `connectAttributes()`. The plugin's update route remains configurable through normal scoped routing. The demo sends a CSP that allows same-origin scripts without `unsafe-eval` or inline scripts.

Every PHP/TypeScript class, including tests and demo classes, needs a concise docblock describing its responsibility. Do not weaken assertions, suppress rules, exclude production code, or add coverage-ignore annotations to meet a numerical goal.

## Documentation and releases

```sh
npm run docs:build
npm run package:composer
npm pack --dry-run
```

The docs use the `/cakephp-interact/` Pages base. Package builds supply an npm module with declarations and a standalone `webroot/js/runtime.js` for Composer users. The `package:composer` command builds a reproducible archive under `build/releases/` with PHP source, the standalone bundle, and third-party licenses. Source checkouts require a browser build. Publishing to Packagist must use a distribution containing those generated assets; a default GitHub source archive does not contain them. The workflow validates packages but does not publish them.

Report actual checks and unavailable tooling. Do not commit installed dependencies, reports, caches, credentials, or temporary outputs.
