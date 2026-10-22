---
title: Navigation and a persistent notebook
description: Navigate between ordinary CakePHP pages while retaining an authorized layout draft and application-owned loading feedback.
---

# Navigation and a persistent notebook

This demo keeps a notebook island while replacing an ordinary page and its counter. Follow [installation](/installation), then run the [demo application](/examples#run-the-demo) and open `/navigation`.

The notebook draft is writable state. Its action counts interactions without saving anything. Both destination mounts and later updates check the current session role. The application's FormHelper renders the controls, and the application stylesheet owns the progress bar.

::: code-group

<<< ../../tests/test_app/src/Island/NavigationNotebook.php{php} [Island]

<<< ../../tests/test_app/templates/element/Interact/navigation_notebook.php{php} [Element]

<<< ../../tests/test_app/templates/Navigation/index.php{php} [Page]

<<< ../../tests/test_app/src/Controller/NavigationController.php{php} [Controller]

:::

The example's wrapper is outside its embedded islands. The shared demo layout separately persists its navigation header and renders a delayed indicator using `$store.interactNavigation.loading()`. No plugin CSS is required.

The layout's Navigation link uses `x-interact:navigate.trail` to stay highlighted on `/navigation/details`. The Overview and Details tabs keep exact-page matching. See [active trails](/directives/navigate#active-trails) for matching rules and application-owned styling.

Changing the notebook's `edition` prop intentionally replaces its client. The Overview and Details tabs preserve page scroll; Details also prefetches on hover or focus. The page counter mounts fresh on every visit. The notebook filter demonstrates URL-state ownership; the notebook draft survives without implicit synchronization or persistence.

See [x-interact:navigate](/directives/navigate) for events, head assets, history, scroll, and feedback. See [x-interact:persist](/directives/persist) for compatible mount requirements and authorization boundaries.

The optional lifecycle panel reuses the existing document-upload and polling examples. It demonstrates retained staged files and polling clients without introducing another upload or job API.
