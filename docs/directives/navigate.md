---
title: x-interact:navigate
description: Visit normal CakePHP pages without restarting Alpine or explicitly persistent layout regions.
---

# x-interact:navigate

`x-interact:navigate` requests a normal CakePHP page and replaces the current page content without restarting Alpine. It works inside and outside islands.

::: code-group

```html [HTML]
<a href="/articles" x-interact:navigate>Articles</a>
```

```php [FormHelper]
<?= $this->Html->link('Articles', '/articles', ['x-interact:navigate' => true]) ?>
```

:::

The helper example uses CakePHP's HtmlHelper for links. Both examples keep a real `href` for native navigation.

## Page requests

The destination runs through its ordinary GET route, middleware, controller, authorization, and template. Return a complete HTML document with the Interact helper loaded and the layout's `script` block rendered, as in [installation](/installation). No navigation endpoint or client router is required.

Page-owned islands mount fresh. Use [x-interact:persist](/directives/persist) to retain explicit layout regions and compatible embedded islands.

Modified clicks, downloads, external links, links targeting another browsing context, and same-page fragments keep native behavior. Forms are not intercepted. Failed assets, unsupported documents, changed session or security policy, and incompatible deployments fall back to native navigation. Actions are never automatically replayed.

## Prefetching

By default, an unmodified primary mouse-button press starts fetching the
destination. Releasing the button over the link activates the visit; pressing
alone never changes the page. Modified clicks, downloads, external links, and
links targeting another browsing context keep their native behavior.

Use `.hover` to start earlier:

```html
<a href="/articles" x-interact:navigate.hover>Articles</a>
```

`.hover` starts fetching after 60 ms of hover or keyboard focus. Leaving before that delay cancels the pending prefetch. Prefetched HTML remains inert: no scripts, islands, polling, uploads, or deferred mounts initialize.

A click joins an in-flight mouse-down or hover request. Completed responses are
retained only when their Cache-Control permits explicit freshness, for at most
30 seconds, and consumed once. `no-store`, `no-cache`, and responses without
explicit freshness are not retained: if their prefetch finishes before the
click, the visit makes a fresh GET. Navigation and island requests invalidate
unused prefetched pages.

Use `.no-prefetch` to disable mouse-down and hover prefetching for a link:

::: code-group

```html [HTML]
<a href="/reports" x-interact:navigate.no-prefetch>Reports</a>
```

```php [FormHelper]
<?= $this->Html->link('Reports', '/reports', ['x-interact:navigate.no-prefetch' => true]) ?>
```

:::

The link still uses enhanced navigation on click or keyboard activation.
`.no-prefetch` takes precedence over `.hover` in either modifier order.
Prefetching adds GET requests even when a user abandons a click; destination
GET routes must remain safe.

## Scroll and focus

New visits scroll to the top, or to the fragment target. Back and Forward fetch fresh HTML and restore the history entry's page scroll. Same-page history created by [URL bindings](/guide/url-state) continues restoring island properties without replacing the document.

```html
<a href="/articles?page=2" x-interact:navigate.preserve-scroll>Next page</a>
```

`.preserve-scroll` retains the page's current scroll on that visit. Scrollable regions inside retained DOM keep both scroll axes automatically. Retained focused controls keep focus and selection; otherwise the destination's autofocus control or main content receives focus.

## Active links

Matching navigation links automatically receive `aria-current="page"`, including links inside persistent regions. Matching uses the same origin and exact path, ignores query strings and fragments, and normalizes trailing slashes.

```css
nav a[aria-current='page'] {
  font-weight: bold;
}
```

Classes and styling remain application-owned. No additional active-link directive is required.

### Active trails

Add `.trail` to mark a section link on its own page and descendant paths:

::: code-group

```html [HTML]
<a href="/articles" x-interact:navigate.trail>Articles</a>
```

```php [FormHelper]
<?= $this->Html->link('Articles', '/articles', ['x-interact:navigate.trail' => true]) ?>
```

:::

Interact manages the boolean `data-interact-active-trail` attribute; do not set it yourself. Links without `.trail` keep exact-page matching and receive no trail attribute. `aria-current="page"` continues to identify only exact matches.

For the link above:

| Current path        | `aria-current="page"` | `data-interact-active-trail` |
| ------------------- | --------------------- | ---------------------------- |
| `/articles`         | Present               | Present                      |
| `/articles/42/edit` | Absent                | Present                      |
| `/articles-archive` | Absent                | Absent                       |

```css
nav a[data-interact-active-trail] {
  font-weight: bold;
}

nav a[aria-current='page'] {
  text-decoration: underline;
}
```

Trail matching requires the same origin and complete, case-sensitive path segments. It ignores query strings and fragments and normalizes trailing slashes. A link to `/` matches only the root page. Matching follows URL ancestry, independently of CakePHP route names.

Both attributes update when links initialize, destinations change, or the current URL changes, including Back/Forward and links inside persistent regions. Combine `.trail` with `.hover` and `.preserve-scroll` in any order.

## Loading feedback

The Alpine store `$store.interactNavigation` describes page visits independently of any island's `$server` request activity. Interact ships no indicator markup, CSS file, or injected styles.

Use Alpine's `x-show` and attribute bindings to present this activity, just as
with [island request feedback](/guide/alpine#events-and-request-feedback).

```html
<span
  x-data
  x-show="$store.interactNavigation.loading({ delay: 300 })"
  x-cloak
  role="status"
>
  Loading page…
</span>
```

| Member                    | Behavior                                        |
| ------------------------- | ----------------------------------------------- |
| `pending`                 | True immediately during a visit                 |
| `loading()`               | True after 200 ms of continuous activity        |
| `loading({ delay: 300 })` | Use an indicator-specific delay in milliseconds |

The default delay follows [JavaScript configuration](/configuration#javascript-configuration). Delay accepts whole milliseconds from 0 to 60,000. Activity includes fetching, required assets, replacement, and initialization. Prefetching alone does not show feedback. Completion, cancellation, and failures clear timers; older requests cannot hide feedback for a newer visit. Use your application's existing `x-cloak` rule.

See the [tested navigation example](/demo#navigation) for an application-styled top bar.

## Document events

These standard DOM events are dispatched on `document` and can also be observed with Alpine's `.document` event modifier.

| Event                       | Timing                                                                          |
| --------------------------- | ------------------------------------------------------------------------------- |
| `interact:navigate`         | Before starting a visit; cancelable                                             |
| `interact:navigating`       | Immediately before replacing page content                                       |
| `interact:navigated`        | After Alpine initialization, focus, and scroll; also once after initial startup |
| `interact:navigation-error` | Before a failed visit falls back to native navigation                           |

`event.detail.url` is the destination URL object. `event.detail.history` identifies a cross-page Back/Forward visit. The error event additionally exposes `cause` for diagnostics.

```js
document.addEventListener('interact:navigate', (event) => {
  if (hasUnsavedChanges() && !confirm('Leave this page?')) {
    event.preventDefault();
  }
});

document.addEventListener('interact:navigated', () => {
  initializePageIntegrations();
});
```

The functions above are application-supplied. Global listeners survive page visits; register them once. Use Alpine cleanup for page-local widgets. `DOMContentLoaded` and `alpine:init` do not fire again.

## Head elements and scripts

Navigation updates the title, document attributes, canonical links, and metadata. Inline `<style>` elements are replaced with the destination's styles on each visit. Existing external assets are retained for the document's lifetime. New stylesheets load before displaying the destination; new external scripts execute once before incoming Alpine initialization. Register late-loaded Alpine components immediately when Alpine already exists, rather than waiting for `alpine:init`.

Scope page-specific CSS. Executable inline scripts, `<base>`, and `http-equiv` metadata require a native visit in this version. Data scripts such as `application/json` remain inert. Alpine and Interact bootstrap scripts are not rerun.

Interact's document middleware records compatibility after application middleware has produced the response. Keep it outside middleware that changes document security headers. If later plugin or infrastructure middleware changes those headers, place Interact's document middleware outside it too, or use native navigation for those pages. Navigation never replaces the original browser CSP or rewrites nonces.

Set [`Interact.navigationVersion`](/configuration#navigation-compatibility) when application-owned assets change incompatibly. The built-in runtime fingerprint is tracked automatically. See [security](/security) and [redirects](/guide/redirects-and-downloads).

## Options

| Modifier           | Behavior                                                                  |
| ------------------ | ------------------------------------------------------------------------- |
| `.hover`           | Prefetch after 60 ms of hover or focus                                    |
| `.no-prefetch`     | Disable mouse-down and hover prefetching while retaining enhanced visits  |
| `.preserve-scroll` | Retain current page scroll for the visit                                  |
| `.trail`           | Mark the current page and URL ancestors with `data-interact-active-trail` |

The directive accepts no expression. Combine modifiers when needed.
