# Contributor and agent guidelines

These instructions apply to the plugin, TypeScript runtime, demo application, tests, documentation, and release tooling.

## Purpose and architecture

- Build CakePHP Interact: reactive, server-rendered PHP islands, independent of Livewire and htmx.
- Target PHP 8.5 and CakePHP 6. Declare supported versions and test them in CI.
- Use `Island`, optional `Render` attributes, and `IslandContext`. Resolve conventional `Island`-suffixed classes on demand using CakePHP-style application/plugin names; keep matching templates under `templates/island/`. CakePHP controller components retain their existing meaning.
- Separate class resolution, metadata compilation, state transport, authorization, actions, rendering, and browser synchronization.
- Construct fresh islands per request through CakePHP's container. Cache declarations only.
- Require explicit declarations for mount props, transported state, writable state, and callable actions.
- Keep services, entities, and queries out of snapshots. Never deserialize arbitrary PHP objects.
- Reuse native templates and elements. Page islands can reuse action templates; embedded islands must not recursively render their hosting template.
- Supply island template data consistently on initial and subsequent renders. Controller view variables are not implicitly transported.
- Keep validation and persistence in the application. Synchronization never implies saving.
- Use native fetch, Alpine, and Alpine Morph. Native XMLHttpRequest is reserved for upload progress and cancellation. URL bindings belong to explicit writable State declarations. Keep enhanced document navigation in an internal Alpine integration using normal CakePHP GET routes; defer form-adapter integration.
- Use `x-interact:model` with synchronization modifiers and `x-interact:submit` for native form protection. Actions use `$server.call()` in Alpine expressions; ordinary events and presentation use Alpine directives. Keep generated `data-interact-*` metadata runtime-owned, without parallel public binding attributes.
- Use form-level `x-interact:model="data"` for native controls backed by writable array drafts. Infer names relative to that path, preserve explicit absolute control bindings, and keep entity initialization, marshalling, validation, association authorization, and saving in application code. Keep `x-interact:submit` separate; it does not collect unbound controls.
- Declare files with `#[Upload]`; keep PSR file objects request-local and transport only owned opaque references. Render accepted files in PHP and use `$server.uploads` for transfer feedback and controls. Image processing and preview URLs belong to the application.
- Use `x-interact:poll` for managed periodic refreshes. Keep refreshes and actions on the existing `$server` bridge, and use Alpine conditions for application-controlled pauses or completion.
- Use `x-interact:ignore` for widget-owned DOM and `x-modelable` with `x-interact:model` for custom values. Keep library instances outside reactive state, manage their lifecycle through application Alpine wrappers, and leave server-rendered errors outside protected regions.
- Use State URL options for query bindings and the existing `$server` bridge for updates. Keep URL parameter ownership explicit, preserve unrelated drafts and history state, and never replay actions on Back/Forward.
- Choose embedded loading with the mount call’s `loading` option (eager, lazy, defer, manual). Pending tickets allow initialization only; authorize placeholders and loading requests, defer mount/render work, and keep manual loading on `$server.sync()`. Custom placeholders receive declared props only.
- Use `redirect()` and `downloadUrl()` only in declared actions. Preserve CakePHP page routing defaults in signed identity; keep downloads as independently authorized native requests and enhanced navigation explicit through redirect(navigate: true).
- Use x-interact:navigate for opt-in links and x-interact:persist for explicit layout regions outside page islands. Coordinate document and URL-state history, preserve compatible layout clients, and keep navigation feedback in the page-level interactNavigation Alpine store. Applications own indicator markup and CSS; loading delays belong in Alpine queries.
- Use `#[Action(render: false)]` and action-only `skipRender()` for state and events without view work. Preserve authorization, validation rendering and its subsequent cleanup, signed state, child identities, draft reconciliation, and normal rendering for synchronization, initialization, uploads, and history restoration. Keep persistence explicit and retain the existing `$server.call()` contract.
- Use Alpine shorthand consistently in code and documentation: `@event` for event listeners and `:attribute` for bindings.

## Usability and cognitive load

- Follow CakePHP's convention-driven usability: make ordinary tasks work with familiar concepts, sensible defaults, and minimal application plumbing.
- Evaluate public APIs from the application author's perspective. Consider the setup, repeated markup, callbacks, imports, and concepts required for a complete working interaction, not just the size of the plugin implementation.
- Prefer handling routine framework integration and bookkeeping inside the plugin. Keep application decisions such as authorization, validation rules, persistence, and presentation explicit.
- Introduce new abstractions and mandatory configuration only when they solve a concrete need. Offer advanced control without making every application configure the advanced path.
- Treat simplicity as a design preference, not an absolute rule. Evaluate tradeoffs for each feature; retain explicit setup or handling when it materially improves correctness, security, clarity, or application control, and explain why it is needed.

## Simplicity and refactoring

- Prefer the smallest clear implementation that meets current requirements. Apply DRY to shared knowledge and rules that must change together; similar syntax alone does not justify consolidation.
- Extract a helper, class, or interface when it has a clear responsibility and reduces the total reasoning needed by callers. Keep straightforward duplication when sharing would add indirection, configuration flags, or unrelated coupling. Reconsider abstractions that accumulate caller-specific branches.
- Follow YAGNI: require a demonstrated use for new flexibility, configuration, or extension mechanisms. Prefer deleting unnecessary code and using existing capabilities before adding another abstraction.
- Evaluate the whole change: production code, tests, setup, public API, and navigation between files. Line count is a useful signal, not a target; explain added concepts or plumbing through a concrete correctness or maintenance benefit.
- Keep substantial refactoring separate from behavior changes. Preserve observable behavior and security boundaries, use existing meaningful tests where sufficient, and add regression tests for demonstrated defects or uncovered contracts.
- Cleanup reviews may conclude that no change is warranted. Distinguish defects from optional improvements and style preferences; do not create more work merely to produce a list of candidates. Explicit user requirements take precedence over these defaults.

## CakePHP and PHP

- Use `declare(strict_types=1);`, PSR-4, explicit types, and CakePHP conventions.
- Use CakePHP's plugin lifecycle, container, Attribute Resolver, routing, translation, requests, and security facilities.
- Prefer cohesive objects and composition. Use interfaces at real extension boundaries.
- Keep PHP plugin classes extensible by default: consumers may subclass services, helpers, views, context objects, and metadata. Use protected methods and properties instead of private implementation members; retain constructor injection for behavior services.
- Do not use final classes or methods merely because a class is small, immutable, or a utility. Readonly classes can remain inheritable. Use late static binding in overridable static workflows so recursive calls honor subclass behavior.
- Use final or private only when a concrete invariant requires closing that extension point, and explain that reason in the declaration's docblock. The built-in declaration attributes remain final because metadata compilation recognizes their exact marker classes; subclassed attributes are not recognized. Preserve intentional invalid-declaration fixtures in tests.
- Every class must have a docblock immediately above its declaration (and before declaration attributes). In a few informative sentences, explain its responsibility and place in the workflow; describe important ownership or security boundaries when relevant. Avoid restating the class name, lengthy implementation inventories, or boilerplate. This applies to PHP and TypeScript classes, including test and demo classes.
- Use multiline declaration docblocks with `*` prefixes and the closing `*/` on its own line. Start with a responsibility summary, separate explanatory paragraphs and tags with blank lines, and give methods meaningful behavior summaries rather than tag-only comments. Use concise examples for public extension points where useful. Keep local PHP `@var` type assertions compact.
- Document public contracts, array shapes, generics, and exceptions. Keep inline comments focused on unclear intent.
- Use imported class names in executable code.
- Use braces for PHP logic. Alternative control syntax is only for blocks crossing PHP/HTML boundaries.
- Escape output by default. Preserve native semantics, stable IDs, accessible errors, focus, and CSP compatibility.
- Preserve missing values, null, false, zero, and empty strings distinctly.
- Run `initialize()` after construction for request-local setup, before props/state assignment and authorization. Keep protected resource access and draft initialization in authorized hooks. Authorize initial mounts and every update before application behavior/actions/rendering. Action arguments require resource authorization too.
- Preserve CSRF and application middleware scopes. Signed snapshots provide integrity, not secrecy, authorization, or exactly-once execution.
- No hidden service lookups, automatic persistence, host-specific assumptions, unrelated dependencies, or vendor edits.

## Demo application and model finders

- Keep the demo application self-contained. Documentation may import demo source; the demo must not require templates, classes, or schema from documentation fixtures.
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
- `InteractHelper::render()` has an approved, method-local exception to the five-parameter lint limit: its seven named mount options, including child model bindings, keep ordinary template usage readable. Keep the limit unchanged elsewhere.
- No baselines, coverage-ignore annotations, disabled rules, or suppressions without explicit user authorization.
- PHPUnit fails on warnings, risky tests, and deprecations.
- Fix PHP and PHPUnit notices when they appear; do not suppress or ignore them.
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
- Browser specs import the shared `tests/browser/fixtures.ts` test API. Keep each parallel worker's PHP server, database, sessions, and upload storage isolated; release processes and temporary storage on teardown.
- Prefer role/label locators, auto-waiting assertions, isolated contexts, deterministic fixtures, and network interception over fixed sleeps.
- Test tampering, CSRF, expiry, unauthorized actions, permission changes, malformed payloads, races, submit-before-blur, validation, escaping, focus, disposal, and nested-state preservation regardless of coverage totals.
- Provide npm scripts `typecheck`, `lint`, `format-check`, `format`, `test`, `test:watch`, `test:coverage`, `test:browser`, `build`, and `check`.
- `check` runs Vite+ type checking, linting, format verification, and unit tests. CI separately runs coverage, browser tests, package builds, and docs builds.

## Distribution, documentation, and completion

- Create pull requests only with the GitHub CLI (`gh`) or a dedicated GitHub MCP server. Never create pull requests through the Codex connector, including `mcp__codex_apps__github_create_pull_request`.
- Supply a Composer-ready browser bundle and an npm integration for application-owned Alpine. Verify both distributions, deduplication, and CSP.
- Treat release assets as intentional reproducible artifacts. Exclude installed dependencies, temporary output, caches, credentials, and reports from commits.
- Commit dependency lockfiles. Keep Node tooling separate from PHP runtime dependencies.
- Maintain VitePress docs with a concise README. Document minimum working examples, lifecycle, security limits, and public contracts. Verify examples and links.
- Run affected checks during development and the full relevant quality workflow before handoff. Review automatic fixes and rerun affected tests.
- CI runs `composer validate --strict`, `composer check`, and `composer test-coverage` with a coverage driver enabled, plus the JavaScript/browser/documentation gates.
- Report actual checks, coverage, failures, and missing tooling. Never present skipped checks as passing.

## Documentation style

- Keep `README.md` a concise introduction and entry point to the documentation. Include the purpose, current compatibility/status, a small set of useful documentation links, and the license. Put installation steps, extended examples, demo commands, API contracts, troubleshooting, and contributor workflows in the docs rather than duplicating them in the README.
- Start feature and API pages with a direct definition of what the feature does, then minimal syntax, behavior, variations, and options. Use concrete API names and headings; avoid motivational introductions, narrative filler, repeated setup, and text that only restates code. Keep one canonical page per topic and link to it from related pages.
- Keep the homepage short and show cooperating PHP classes, elements, and mount calls in labeled code tabs. Use short role-based tab labels such as Island, Element, Table, and Mount instead of file paths; identify example file paths in PHP docblocks where needed. Identify imports, prerequisites, and application-supplied data.
- Give new users a complete path from installation to a working interaction. Keep development-package and browser-asset requirements visible rather than hiding them in optional details.
- Use conventional application/plugin island names consistently in rendering examples. Make the ownership of props, writable drafts, computed data, authorization, and persistence explicit where relevant.
- Prefer VitePress navigation, local search, code groups, callouts, and optional details over custom components. Reserve callouts for actionable caveats; omit line numbers unless useful.
- Keep complete interactions in the demo application and link their tested source from the Demo page. Keep short, focused examples within feature and API pages. Do not maintain a separate documentation application.
- For small control and form examples, use a code group with HTML first and FormHelper second, demonstrating equivalent behavior. Use the labels HTML and FormHelper; HTML is the default tab. Use a single HTML snippet for containers or Alpine presentation when a helper alternative adds nothing. Complete CakePHP examples continue to use FormHelper.
- Give each public `x-interact:*` directive a dedicated directive page with a minimal example, common variations, and supported options. Keep bridge members on the `$server` page, link related feature and example pages, and preserve existing published routes and anchors when consolidating contracts.
- Keep public introductory examples free of authorization boilerplate. Demonstrate `authorize()` where examples use protected resources or teach access control.
- Keep template source readable when imported into documentation: split nested HTML and long attribute lists across lines rather than compressing whole panels into single lines.
- Preserve published page routes and linked anchors when reorganizing. Build the site with link checking enabled, check example syntax and behavior, and inspect desktop and mobile reading/navigation before handoff.
