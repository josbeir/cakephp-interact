---
title: Contributing
description: Set up the repository, run its PHP and browser quality gates, maintain examples, and build distributions.
---

# Contributing

Read the root [contributor guidelines](https://github.com/josbeir/cakephp-interact/blob/main/AGENTS.md)
before changing code. Use PHP 8.5, CakePHP 6 development builds, and Node 24.11+ or Node 26+.
For installation and fixture setup, start with [the demo](/examples).

## PHP checks

```sh
composer validate --strict
composer check
composer test-coverage
```

`check` runs PHPUnit, Mago analysis, Mago lint, Rector dry run, and formatting
verification. `rector-fix` applies refactorings followed by Mago formatting.
Preserve approved Rector skips and CakePHP's Mago preset.

Coverage needs Xdebug in coverage mode or PCOV. Include unexecuted source and
require at least 95% unrounded line coverage across production `src/`. Missing
reports and empty executable-source measurements fail the explicit gate.
PHPUnit also fails on warnings, risky tests, and deprecations.

::: details Optional branch coverage
With a compatible Xdebug build, `composer test-coverage-branches` writes method,
line, branch, and path coverage to `build/php-branches.txt`. PHP 8.5.11 with
Xdebug 3.5.3 crashes during branch/path instrumentation in this development
environment; ordinary line coverage works. Branch reporting remains optional
until that toolchain issue is resolved.
:::

<span id="browser-runtime"></span>

## JavaScript and browser checks

```sh
npm run check
npm run test:coverage
npx playwright install --with-deps chromium firefox webkit
npm run test:browser
```

`check` runs Vite+ format verification, Oxlint (including type-aware rules),
native type checking, and Vitest. V8 coverage
includes unexecuted runtime source and separately requires at least 95%
unrounded line coverage. Statements, functions, and branches are also reported.

JavaScript tooling is configured together in `vite.config.ts`: `lint` owns
Oxlint rules, `fmt` owns Oxfmt formatting, `test` owns Vitest and coverage, and `pack` builds both browser distributions.
Use `npm run lint`, `npm run format`, or `npm run typecheck` for a focused check.
Vite+ supplies these tools and Vitest, so no separate ESLint, Prettier, or Vitest
installation is needed. Tests import their APIs from `vite-plus/test`.

TypeScript is a normal version 7 dependency. Vite+ uses its native type checker;
`npm run build` uses `vp pack` to bundle the npm module and standalone CSP
runtime, and emits declarations with the native TypeScript 7 generator. There are no
TypeScript compatibility aliases or second compiler installations.

Playwright exercises the real CakePHP application in Chromium, Firefox, and
WebKit. Test observable behavior, security failures, races, validation, focus,
and nested ownership even when coverage totals already pass. PHP integration
tests exercise the middleware stack in process.

Runtime modules live under `resources/ts`: bindings own controls, scheduling
serializes requests, state modules validate and reconcile drafts, transport
validates HTTP envelopes, and DOM modules own morphing and selection.
`client.ts` coordinates them; `index.ts` installs the Alpine integration.

## Keep examples trustworthy

Demo controllers use routing attributes connected through `connectAttributes()`.
Islands use `LocatorAwareTrait::fetchTable()` for Tables; domain queries belong in
named finders. Keep other behavior services injectable.

Give every PHP/TypeScript class an informative responsibility docblock, including
tests and demos. Escape output, use Alpine shorthand, and preserve native form
semantics. Do not suppress rules, weaken assertions, exclude production code, or
add coverage-ignore annotations to reach a target.

For documentation, explain a task, show cooperating files, and describe what the
reader should observe. Keep setup prerequisites visible. Prefer importing tested
demo source for full examples and put exhaustive contracts in reference pages.
Check snippet syntax, rendering behavior, links, and desktop/mobile navigation.

## Documentation and releases

```sh
npm ci
npm run docs:build
npm run package:composer
npm pack --dry-run
```

For local reading, run `npm run docs:dev` and use the URL it prints, including
the `/cakephp-interact/` base path.

The [published documentation](https://josbeir.github.io/cakephp-interact/) uses
GitHub Pages. The Documentation workflow builds documentation changes on pull
requests and pushes to `main`. Only `main` deploys to the `github-pages`
environment; pull requests build without deployment permissions. Manual workflow
runs can publish `main`. Pages settings must use **GitHub Actions** as the source.

Package builds supply an npm module with declarations and a standalone
`webroot/js/runtime.js`. `package:composer` creates a reproducible archive under
`build/releases/` with PHP source, docs, the standalone bundle, and third-party
licenses. Source checkouts need a browser build. A default GitHub source archive
does not include generated assets. The current Packagist `dev-main` distribution
uses that source archive, so consumers must [build the browser runtime](/installation#build-the-browser-runtime)
after installation. There is no stable release yet. Composer release distributions
must include generated assets. The Quality workflow validates packages but does
not publish them.

Run affected checks and the full relevant workflow before handoff. For docs-only
changes, verify documentation and examples without claiming unrelated PHP checks.
Report actual results and unavailable tooling. Keep dependencies, caches, reports,
and credentials out of commits.
