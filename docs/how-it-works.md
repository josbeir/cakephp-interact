---
title: How it works
description: Follow a CakePHP page from its controller and hosting view through independent PHP islands, native templates, and browser updates.
---

# How it works

Interact mounts interactive regions inside a CakePHP view. Each island has a PHP
class, its own declared state, and a native template or element. Alpine connects
that region to PHP; Interact returns HTML when its state changes.

## One view, several islands

On an initial page request, the controller renders its normal view. Calls to
`$this->Interact->render()` mount islands wherever their HTML belongs. This
example mounts `Counter` and `ResourceSearch` side by side.

<figure class="interact-flow" aria-labelledby="composition-caption">
  <div class="flow-heading"><span class="flow-dot"></span> Initial page request <span class="flow-tag">Server → browser</span></div>
  <div class="flow-node">
    <span class="flow-label">CakePHP route</span>
    <strong>Controller action</strong>
    <span>Prepares the surrounding page.</span>
  </div>
  <div class="flow-arrow" aria-hidden="true">↓</div>
  <div class="flow-node">
    <span class="flow-label">Hosting view · AppView</span>
    <strong>Layout + page template</strong>
    <span>Normal page content, with a mount call for each island.</span>
  </div>
  <div class="flow-branches">
    <div class="flow-lane">
      <div class="flow-arrow" aria-hidden="true">↓</div>
      <div class="flow-node flow-mount">
        <span class="flow-label">Mount Counter</span>
        <code>render('Counter')</code>
      </div>
      <div class="flow-arrow" aria-hidden="true">↓</div>
      <div class="flow-node flow-island">
        <span class="flow-label">PHP island</span>
        <strong>CounterIsland</strong>
        <span>Count state + increment action</span>
      </div>
      <div class="flow-arrow" aria-hidden="true">↓</div>
      <div class="flow-node">
        <span class="flow-label">Island renderer · TemplateView</span>
        <strong>counter.php</strong>
        <span>Native island template</span>
      </div>
      <div class="flow-arrow" aria-hidden="true">↓</div>
      <div class="flow-node flow-browser">
        <span class="flow-label">Browser region</span>
        <strong>Counter HTML + Alpine</strong>
        <span>Own state and request queue</span>
      </div>
    </div>
    <div class="flow-lane flow-lane-search">
      <span class="flow-mobile-branch">Another mount in the same hosting view</span>
      <div class="flow-arrow" aria-hidden="true">↓</div>
      <div class="flow-node flow-mount">
        <span class="flow-label">Mount ResourceSearch</span>
        <code>render('ResourceSearch')</code>
      </div>
      <div class="flow-arrow" aria-hidden="true">↓</div>
      <div class="flow-node flow-island">
        <span class="flow-label">PHP island</span>
        <strong>ResourceSearchIsland</strong>
        <span>Search state + result data</span>
      </div>
      <div class="flow-arrow" aria-hidden="true">↓</div>
      <div class="flow-node">
        <span class="flow-label">Island renderer · TemplateView</span>
        <strong>resource_search.php</strong>
        <span>Native island template</span>
      </div>
      <div class="flow-arrow" aria-hidden="true">↓</div>
      <div class="flow-node flow-browser">
        <span class="flow-label">Browser region</span>
        <strong>Search HTML + Alpine</strong>
        <span>Own state and request queue</span>
      </div>
    </div>
  </div>
  <figcaption id="composition-caption">Follow each mount from PHP to its browser region. Both independent islands belong to the same HTML document.</figcaption>
</figure>

The mount names resolve to `App\Island\CounterIsland` and
`App\Island\ResourceSearchIsland`. Their templates live under `templates/island/`.
Each mount constructs its island, calls `initialize()`, assigns declared props,
authorizes, calls `mount()`, and renders. See the full [lifecycle](/guide/lifecycle).

Each island renders through a separate `TemplateView`. It receives declared
props, state, errors, and `viewData()` on both initial and subsequent renders.
Controller view variables and the hosting `AppView`'s helper configuration do
not automatically cross into island content. Pass initial values through
[`props`](/guide/islands#passing-initial-values).

## From interaction to HTML update

Clicking a counter button calls `$server.call('increment')`. A model binding can
also synchronize writable state. Both use the owning island's update endpoint.

<figure class="interact-flow" aria-labelledby="update-caption">
  <div class="flow-heading"><span class="flow-dot"></span> An island update <span class="flow-tag">Repeat per interaction</span></div>
  <div class="flow-node flow-browser">
    <span class="flow-label">1 · Browser</span>
    <strong>Interact captures the interaction</strong>
    <span>Send the signed snapshot, writable edits, and any action through fetch.</span>
  </div>
  <div class="flow-arrow" aria-hidden="true">↓</div>
  <div class="flow-node">
    <span class="flow-label">2 · CakePHP update endpoint</span>
    <strong>Verify, restore, and authorize</strong>
    <span>Verify the snapshot. Construct a fresh PHP island, initialize it, restore declared values, and apply accepted inputs before authorization.</span>
  </div>
  <div class="flow-arrow" aria-hidden="true">↓</div>
  <div class="flow-node flow-island">
    <span class="flow-label">3 · Authorized PHP behavior</span>
    <strong>Run hooks and the requested action, then render</strong>
    <span>Call updated() for changes and any declared action. TemplateView renders the island with its current data.</span>
  </div>
  <div class="flow-arrow" aria-hidden="true">↓</div>
  <div class="flow-node flow-browser">
    <span class="flow-label">4 · Browser</span>
    <strong>Accept state and morph this island's HTML</strong>
    <span>Alpine Morph preserves newer drafts, focus, and retained local Alpine state. Other islands keep their own state.</span>
  </div>
  <figcaption id="update-caption">The browser keeps the new signed snapshot for the next interaction. The original controller action, layout, and mount() do not run again.</figcaption>
</figure>

CakePHP's CSRF and application middleware still apply. A signed snapshot protects
integrity; each request still needs current authorization. Validation and saving
remain application code, and synchronizing state does not save it.

This chart shows the normal rendered response. [Renderless actions](/guide/actions#renderless-actions)
return accepted state and events without rendering HTML. [Redirects and downloads](/guide/redirects-and-downloads)
use the same authorized action lifecycle.

## Nested and page islands

An island template can mount child islands with the same helper. Each child has
its own PHP class, template, state, and requests. Stable keys let a parent update
preserve matching children and their drafts. Use explicit [reactive props or
model bindings](/guide/nesting) to connect parent and child values, and
[events](/guide/events) to coordinate siblings.

For a [page island](/guide/page-islands), the controller selects `IslandView`
instead of the normal hosting view. `IslandView` mounts the page island and wraps
its content in the normal layout on the initial GET. `TemplateView` still renders
the island content, which can reuse an action template. Later interactions follow
the same update flow above.

See [Rendering and helpers](/reference/rendering) for view customization, or follow
the [Quickstart](/getting-started) to build the counter.

<style scoped>
.interact-flow {
  margin: 24px 0;
  padding: 24px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 16px;
  background: var(--vp-c-bg-alt);
  font-size: 14px;
  line-height: 1.6;
}

.flow-heading {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 20px;
  color: var(--vp-c-text-1);
  font-weight: 600;
}

.flow-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--vp-c-brand-1);
}

.flow-tag {
  margin-left: auto;
  color: var(--vp-c-text-2);
  font-size: 12px;
  font-weight: 400;
}

.flow-node {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  padding: 16px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 10px;
  background: var(--vp-c-bg);
  color: var(--vp-c-text-2);
  overflow-wrap: anywhere;
}

.flow-node strong {
  color: var(--vp-c-text-1);
  font-size: 15px;
  font-weight: 600;
}

.flow-label {
  color: var(--vp-c-text-2);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.flow-arrow {
  height: 32px;
  color: var(--vp-c-text-3);
  font-size: 24px;
  line-height: 32px;
  text-align: center;
}

.flow-branches {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.flow-lane {
  display: grid;
  grid-template-rows: 32px auto 32px 1fr 32px 1fr 32px 1fr;
}

.flow-island {
  border-color: var(--vp-c-brand-1);
  background: var(--vp-c-brand-soft);
}

.flow-mobile-branch {
  display: none;
}

.flow-browser {
  border-color: var(--vp-c-green-2);
  background: var(--vp-c-green-soft);
}

.flow-mount code {
  padding: 0;
  background: transparent;
  font-size: 12px;
  white-space: normal;
}

.interact-flow figcaption {
  margin-top: 20px;
  color: var(--vp-c-text-2);
  font-size: 13px;
}

@media (max-width: 599px) {
  .interact-flow {
    padding: 16px;
  }

  .flow-tag {
    width: 100%;
    margin-left: 16px;
  }

  .flow-branches {
    grid-template-columns: 1fr;
    gap: 0;
  }

  .flow-lane {
    grid-template-rows: none;
  }

  .flow-lane-search {
    margin-top: 20px;
    padding-top: 12px;
    border-top: 1px dashed var(--vp-c-divider);
  }

  .flow-mobile-branch {
    display: block;
    color: var(--vp-c-text-2);
    font-size: 12px;
    text-align: center;
  }
}
</style>
