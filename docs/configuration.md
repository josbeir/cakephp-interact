---
title: Configuration
description: Configure signing, island views, browser assets, uploads, and JavaScript timing defaults.
---

# Configuration

Interact reads PHP settings from the application's `Interact` configuration key.
JavaScript timing overrides belong to the application's Alpine plugin installation.
Every setting is optional.

## PHP configuration

Put application defaults in `config/app.php` and environment-specific values in
`config/app_local.php`. Load configuration before the plugin's services and views
are constructed.

```php [config/app.php — configuration excerpt]
'Interact' => [
    'snapshots' => [
        'ttl' => 1800,
        'key' => null,
    ],
    'view' => [
        'className' => \Interact\View\TemplateView::class,
    ],
    'assets' => [
        'script' => 'Interact.runtime',
        'scriptOptions' => ['block' => 'script', 'defer' => true],
    ],
    'navigationVersion' => '',
    'debug' => [
        // Omit enabled to follow the application's debug mode.
        'viewer' => true,
    ],
    'uploads' => [
        'directory' => TMP . 'interact/uploads',
        'maxFileSize' => '10MB',
        'maxFiles' => 10,
        'maxIslandSize' => '50MB',
        'maxSessionSize' => '100MB',
        'maxSessionFiles' => 100,
        'ttl' => 86_400,
    ],
],
```

Invalid values and unknown keys inside `snapshots`, `view`, `assets`, or `debug` fail when
configuration is resolved. `scriptOptions` accepts native HtmlHelper options.
There is no PHP browser-timing configuration.

### Debug output

`debug.enabled` defaults to the application's CakePHP `debug` mode; explicit
`true` cannot override production mode. `debug.viewer` defaults to `true`.
Set `viewer: false` to keep captured dumps in Network responses without the
built-in UI, or `enabled: false` to disable capture entirely. Both settings
require booleans. See [debugging](/guide/debugging) for JSON, SSE, and DebugKit behavior.

### Snapshots

| Setting         | Default | Behavior                                                                                   |
| --------------- | ------- | ------------------------------------------------------------------------------------------ |
| `snapshots.ttl` | `1800`  | Positive integer lifetime in seconds for each issued snapshot.                             |
| `snapshots.key` | `null`  | Null uses CakePHP's security salt; an explicit signing key must contain at least 32 bytes. |

Each accepted update issues a new snapshot with a fresh lifetime. An expired
snapshot requires a fresh mount. Upload retention does not extend this lifetime.

Keep dedicated keys in environment-specific secret configuration. Changing the
key invalidates snapshots signed with the previous key; key rotation with
overlapping keys is not supported. Snapshots are visible to browsers and do not
replace current authorization. See [security and deployment](/security#snapshots).

Applications replacing `SnapshotCodec` through the container own that service's
configuration; the default registration does not override them.

### Island view class

| Setting          | Default                             | Behavior                                           |
| ---------------- | ----------------------------------- | -------------------------------------------------- |
| `view.className` | `Interact\View\TemplateView::class` | Concrete view class used to render island content. |

Set this to an application class extending `Interact\View\TemplateView`, for
example `App\View\IslandTemplateView::class`. Register helpers in that class's
`initialize()` method after `parent::initialize()`. Retain CakePHP's native view
constructor signature. An ordinary `AppView` is not an island view unless it
extends `TemplateView`.

The selected class renders initial island content, updates, and custom loading
placeholders. Page islands retain their normal outer layout. Hosting controller
variables and helpers are not implicitly copied into the island view.

See [configure a custom island view](/reference/rendering#configure-a-custom-island-view)
for the application class. An application factory override remains authoritative.

### Browser assets

| Setting                | Default                                  | Behavior                                                                                     |
| ---------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------- |
| `assets.script`        | `'Interact.runtime'`                     | Script asset name or URL passed to HtmlHelper; `false` disables automatic runtime inclusion. |
| `assets.scriptOptions` | `['block' => 'script', 'defer' => true]` | Native HtmlHelper script options, merged with these defaults by option name.                 |

For [application-owned Alpine](/installation#application-owned-alpine), disable
the standalone runtime globally:

```php
'Interact' => [
    'assets' => ['script' => false],
],
```

The existing helper `autoScript: false` also prevents automatic inclusion.
Neither switch disables document metadata used by enhanced navigation.

Use `scriptOptions` for an alternative block, integrity/crossorigin attributes,
or a nonce override. HtmlHelper supplies the request's `cspScriptNonce` attribute
automatically when no nonce override is supplied. URLs, attribute escaping, and
script deduplication retain native HtmlHelper behavior.

`scriptOptions.block` must be a nonempty block name; `true` means `script`.
Inline output with `false` or `null` is not supported. The selected block also
receives navigation metadata when automatic runtime inclusion is disabled.
Output it in the layout, using its configured name:

```php
<?= $this->fetch('script') ?>
```

### Navigation compatibility

| Setting             | Default | Behavior                                                                |
| ------------------- | ------- | ----------------------------------------------------------------------- |
| `navigationVersion` | `''`    | Application asset compatibility identifier used by enhanced navigation. |

Set a string build identifier when application-owned assets change incompatibly.
Different identifiers require a native page visit. Alternatively, mark versioned
head bundles with [x-interact:asset="reload"](/directives/asset#reload-on-asset-changes).
The built-in runtime fingerprint is tracked automatically. See [navigation](/directives/navigate#head-elements-and-scripts).

### Temporary uploads

| Setting                   | Default                    | Behavior                                                      |
| ------------------------- | -------------------------- | ------------------------------------------------------------- |
| `uploads.directory`       | `TMP . 'interact/uploads'` | Private staging directory outside the webroot.                |
| `uploads.maxFileSize`     | `10 MiB`                   | Maximum staged bytes per file.                                |
| `uploads.maxFiles`        | `10`                       | Maximum files per multiple-file property.                     |
| `uploads.maxIslandSize`   | `50 MiB`                   | Maximum staged bytes per island in a session and route scope. |
| `uploads.maxSessionSize`  | `100 MiB`                  | Maximum staged bytes per session.                             |
| `uploads.maxSessionFiles` | `100`                      | Maximum staged files per session.                             |
| `uploads.ttl`             | `86400`                    | Positive retention duration in seconds; 24 hours by default.  |

Size settings accept positive integer bytes or CakePHP size strings such as
`'10MB'` and `'1.5GB'`. Counts and retention require positive integers. Singular
upload properties accept one file; `#[Upload]` can tighten field size/count
limits but cannot raise application ceilings.

These settings bound temporary transport/storage; application validation and
permanent persistence remain explicit. Abandoned transfers count until cleanup,
and zero-byte files count toward file limits. PHP and web-server body limits
apply independently. See [private staging and cleanup](/guide/uploads#private-staging-and-cleanup)
for deployment and the cleanup command.

## Existing helper options

Register Interact through the application's normal view/helper setup. These
options apply to that helper instance; they are not additional `Interact` keys.

| Option       | Default     | Behavior                                                                |
| ------------ | ----------- | ----------------------------------------------------------------------- |
| `autoScript` | `true`      | Allow automatic inclusion when `assets.script` is enabled.              |
| `endpoint`   | `null`      | Use named route `interact:update`, or an explicit routed URL.           |
| `scope`      | `'default'` | Server-owned scope bound into snapshots; must match the endpoint route. |
| `document`   | `true`      | Emit the document marker used for enhanced navigation compatibility.    |

Keep custom endpoint URLs and scopes server-owned. See
[custom endpoints](/security#custom-endpoints) for a protected route example.
Mount arguments and declaration attributes remain documented with their APIs:
[islands](/guide/islands), [state](/guide/state), and [actions](/guide/actions).

## JavaScript configuration

The Composer bundle uses the built-in timing defaults. To override application-wide
timings, use [application-owned Alpine](/installation#application-owned-alpine)
and install `createInteract()` before starting Alpine:

```ts [Application JavaScript entry]
import Alpine from '@alpinejs/csp';
import { createInteract } from '@josbeir/cakephp-interact';

Alpine.plugin(
  createInteract({
    defaults: {
      debounce: 400,
      pollInterval: 10000,
      loadingDelay: 300,
    },
  }),
);
Alpine.start();
```

| Option                  | Default | Accepted values and behavior                                                        |
| ----------------------- | ------- | ----------------------------------------------------------------------------------- |
| `defaults.debounce`     | `250`   | Integer milliseconds from 0–60,000 for debounced live model synchronization.        |
| `defaults.pollInterval` | `5000`  | Integer milliseconds from 1–2,147,483,647 for polling without an explicit interval. |
| `defaults.loadingDelay` | `200`   | Integer milliseconds from 0–60,000 for island and navigation loading queries.       |

Options are partial and exported as the `InteractOptions` TypeScript type.
Unknown keys, invalid types, fractions, and out-of-range values fail before
installation. Zero debounce/loading delay is supported.

Explicit model modifiers, polling intervals, loading-query delays, and child
binding delays override installation defaults. The debounce default also applies
to live custom inputs and live child bindings with an omitted delay. It does not
delay native selects, checkboxes, or radios unless their live binding explicitly
includes `.debounce`. It does not change deferred, blur, or change synchronization,
nor native upload timing.

Both `Alpine.plugin(interact)` and `Alpine.plugin(createInteract())` retain the
built-in defaults. Installation captures settings for that Alpine instance;
subsequent installation attempts do not reconfigure it. Enhanced navigation
continues using the installed settings.
