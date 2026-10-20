# Contributor and agent guidelines

These instructions apply to the plugin, TypeScript runtime, demo application, tests, documentation, and release tooling.

## Purpose and architecture

- Build CakePHP Interact: reactive, server-rendered PHP islands, independent of Livewire and htmx.
- Target PHP 8.5 and CakePHP 6. Declare supported versions and test them in CI.
- Use `Island`, `AsIsland`, and `IslandContext`. CakePHP controller components retain their existing meaning.
- Separate discovery, state transport, authorization, actions, rendering, and browser synchronization.
- Construct fresh islands per request through CakePHP's container. Cache declarations only.
- Require explicit declarations for mount props, transported state, writable state, and callable actions.
- Keep services, entities, and queries out of snapshots. Never deserialize arbitrary PHP objects.
- Reuse native templates and elements. Page islands can reuse action templates; embedded islands must not recursively render their hosting template.
- Supply island template data consistently on initial and subsequent renders. Controller view variables are not implicitly transported.
- Keep validation and persistence in the application. Synchronization never implies saving.
- Use native fetch, Alpine, and Alpine Morph. Phase 2 owns navigation and URL-state features; defer form-adapter integration.
- Use Alpine shorthand consistently in code and documentation: `@event` for event listeners and `:attribute` for bindings.

## Usability and cognitive load

- Follow CakePHP's convention-driven usability: make ordinary tasks work with familiar concepts, sensible defaults, and minimal application plumbing.
- Evaluate public APIs from the application author's perspective. Consider the setup, repeated markup, callbacks, imports, and concepts required for a complete working interaction, not just the size of the plugin implementation.
- Prefer handling routine framework integration and bookkeeping inside the plugin. Keep application decisions such as authorization, validation rules, persistence, and presentation explicit.
- Introduce new abstractions and mandatory configuration only when they solve a concrete need. Offer advanced control without making every application configure the advanced path.
- Treat simplicity as a design preference, not an absolute rule. Evaluate tradeoffs for each feature; retain explicit setup or handling when it materially improves correctness, security, clarity, or application control, and explain why it is needed.

## CakePHP and PHP

- Use `declare(strict_types=1);`, PSR-4, explicit types, and CakePHP conventions.
- Use CakePHP's plugin lifecycle, container, Attribute Resolver, routing, translation, requests, and security facilities.
- Prefer cohesive objects and composition. Use interfaces at real extension boundaries.
- Keep behavior services extensible with protected dependencies/methods and constructor injection. Reserve final/readonly classes for suitable immutable data and utilities.
- Every class must have a docblock immediately above its declaration (and before declaration attributes). In a few informative sentences, explain its responsibility and place in the workflow; describe important ownership or security boundaries when relevant. Avoid restating the class name, lengthy implementation inventories, or boilerplate. This applies to PHP and TypeScript classes, including test and demo classes.
- Use multiline declaration docblocks with `*` prefixes and the closing `*/` on its own line. Start with a responsibility summary, separate explanatory paragraphs and tags with blank lines, and give methods meaningful behavior summaries rather than tag-only comments. Use concise examples for public extension points where useful. Keep local PHP `@var` type assertions compact.
- Document public contracts, array shapes, generics, and exceptions. Keep inline comments focused on unclear intent.
- Use imported class names in executable code.
- Use braces for PHP logic. Alternative control syntax is only for blocks crossing PHP/HTML boundaries.
- Escape output by default. Preserve native semantics, stable IDs, accessible errors, focus, and CSP compatibility.
- Preserve missing values, null, false, zero, and empty strings distinctly.
- Authorize initial mounts and every update before application hooks/actions/rendering. Action arguments require resource authorization too.
- Preserve CSRF and application middleware scopes. Signed snapshots provide integrity, not secrecy, authorization, or exactly-once execution.
- No hidden service lookups, automatic persistence, host-specific assumptions, unrelated dependencies, or vendor edits.

## Demo application and model finders

- Put domain queries in named custom Table finders or reusable Behaviors: search, visibility, association loading, dependent choices, and domain ordering.
- Use typed finder parameters and `SelectQuery` return types with meaningful generic annotations.
- Finders return composable queries; callers choose execution/pagination.
- Controllers and islands orchestrate finders; templates never query the database.
- Declare demo controller routes with CakePHP routing attributes and connect them through `connectAttributes()`.
- In the demo app, use CakePHP’s `LocatorAwareTrait::fetchTable()` for Table access rather than constructor-injecting Tables. Keep constructor injection for plugin behavior services and non-Table application dependencies.
- Ordinary primary-key `get()` calls do not need ceremonial finder wrappers.
- Allowlist client-selectable ordering and validate query inputs.
- Keep CakePHP validation, application rules, authorization, and persistence in their normal application layers.
- Test finders with a real SQLite database, including access scopes, empty results, and associations.
- Use deterministic demo fixtures and a bootable app shared by integration and browser tests.

## TypeScript

- Use ES modules, strict TypeScript 7, `noUncheckedIndexedAccess`, and `exactOptionalPropertyTypes`. Support Node 24.11+ or Node 26+ for tooling.
- Use TypeScript 7 directly for native type checking and declaration builds. Avoid compatibility aliases or a second TypeScript compiler.
- Treat network data as `unknown`; validate before use. Prefer discriminated unions and explicit state transitions.
- Avoid `any`, unchecked assertions, non-null assertions, and suppressions. Fix types at their source.
- Separate transport, scheduling, state reconciliation, DOM operations, and Alpine integration.
- Organize runtime responsibilities into focused ES modules and folders. Keep the island client as their coordinator; use module imports and exports rather than TypeScript `namespace` declarations.
- Handle promises and errors explicitly. Never automatically retry actions or confuse aborting fetch with canceling a server operation.
- Clean up listeners, timers, observers, and in-flight work when islands disappear.
- Preserve newer drafts, focus, selection, nested ownership, and local Alpine state during updates.
- Keep request feedback on the `$server` bridge and use Alpine bindings for visibility, disabled states, and classes; avoid introducing parallel loading-attribute conventions.
- Prevent duplicate Alpine initialization. Avoid unnecessary global mutable state.
- Vite+ owns JavaScript/TypeScript linting, formatting, native type checking, Vitest, and library bundling through `vite.config.ts`. Use its bundled Oxlint, Oxfmt, and Vitest instead of separate ESLint/Prettier installations or configuration. Mago owns PHP formatting, linting, and analysis.

## PHP quality gates

- Use the CakePHP Mago preset, discover project source and vendor dependencies, and keep method/property limits at 30.
- Preserve Rector's prepared sets and approved skips for CakePHP assertion style, explicit casts, mixed annotations, fluent return contracts, and normalized template paths.
- No baselines, coverage-ignore annotations, disabled rules, or suppressions without explicit user authorization.
- PHPUnit fails on warnings, risky tests, and deprecations.
- Require at least 95% unrounded line coverage across all production PHP under `src/`, including unexecuted files. Report branch coverage when available.
- Generate Clover with Xdebug/PCOV. An explicit gate rejects missing/invalid reports, missing executable-source data, and coverage below 95%. A scaffold without executable source does not pass.
- Never exclude production code, weaken assertions, or add meaningless tests to satisfy coverage.

Required Composer scripts:

| Script          | Responsibility                                                |
| --------------- | ------------------------------------------------------------- |
| `test`          | PHPUnit                                                       |
| `test-coverage` | Clover generation and explicit coverage gate                  |
| `analyze`       | Mago analysis                                                 |
| `lint`          | Mago linting                                                  |
| `format-check`  | Mago format verification                                      |
| `format`        | Mago formatting                                               |
| `lint-fix`      | Mago fixes, formatting, and failure on remaining findings     |
| `rector`        | Rector dry run                                                |
| `rector-fix`    | Rector fixes followed by Mago formatting                      |
| `check`         | Tests, analysis, linting, Rector dry run, format verification |

## JavaScript and browser quality gates

- Use Vitest and V8 coverage. Require at least 95% unrounded JavaScript/TypeScript line coverage, separately from PHP.
- Explicitly include unexecuted runtime source. Report statements, functions, and branches too.
- Use fake timers for scheduling and controlled doubles at HTTP boundaries.
- Use Playwright against a real CakePHP application in Chromium, Firefox, and WebKit.
- Prefer role/label locators, auto-waiting assertions, isolated contexts, deterministic fixtures, and network interception over fixed sleeps.
- Test tampering, CSRF, expiry, unauthorized actions, permission changes, malformed payloads, races, submit-before-blur, validation, escaping, focus, disposal, and nested-state preservation regardless of coverage totals.
- Provide npm scripts `typecheck`, `lint`, `format-check`, `format`, `test`, `test:watch`, `test:coverage`, `test:browser`, `build`, and `check`.
- `check` runs Vite+ type checking, linting, format verification, and unit tests. CI separately runs coverage, browser tests, package builds, and docs builds.

## Distribution, documentation, and completion

- Supply a Composer-ready browser bundle and an npm integration for application-owned Alpine. Verify both distributions, deduplication, and CSP.
- Treat release assets as intentional reproducible artifacts. Exclude installed dependencies, temporary output, caches, credentials, and reports from commits.
- Commit dependency lockfiles. Keep Node tooling separate from PHP runtime dependencies.
- Maintain VitePress docs with a concise README. Document minimum working examples, lifecycle, security limits, and public contracts. Verify examples and links.
- Run affected checks during development and the full relevant quality workflow before handoff. Review automatic fixes and rerun affected tests.
- CI runs `composer validate --strict`, `composer check`, and `composer test-coverage` with a coverage driver enabled, plus the JavaScript/browser/documentation gates.
- Report actual checks, coverage, failures, and missing tooling. Never present skipped checks as passing.

## Documentation style

- Keep `README.md` a concise introduction and entry point to the documentation. Include the purpose, current compatibility/status, a small set of useful documentation links, and the license. Put installation steps, extended examples, demo commands, API contracts, troubleshooting, and contributor workflows in the docs rather than duplicating them in the README.
- Lead with a concrete task and a small working example, then explain the result. Teach ordinary usage before advanced variations; keep exhaustive contracts in reference pages.
- Keep the homepage short and show cooperating PHP classes, elements, and mount calls in labeled code tabs. Identify file paths, imports, prerequisites, and application-supplied data.
- Give new users a complete path from installation to a working interaction. Keep development-package and browser-asset requirements visible rather than hiding them in optional details.
- Use declared aliases consistently in discovery and rendering examples. Make the ownership of props, writable drafts, computed data, authorization, and persistence explicit where relevant.
- Prefer VitePress navigation, local search, code groups, callouts, and optional details over custom components. Reserve callouts for actionable caveats; omit line numbers unless useful.
- Keep recipes focused; link to shared setup and reference contracts instead of repeating them. Import tested demo source when presenting a full demo implementation.
- Preserve published page routes and linked anchors when reorganizing. Build the site with link checking enabled, check example syntax and behavior, and inspect desktop and mobile reading/navigation before handoff.
