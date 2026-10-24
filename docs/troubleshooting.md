---
title: Troubleshooting
description: Diagnose missing runtime assets, CSRF failures, undeclared variables, expired snapshots, and integration mistakes.
---

# Troubleshooting

Start with the browser's Console and Network panels. A successful interaction
sends a POST to the configured update endpoint and receives JSON containing
rendered HTML. Record both the HTTP status and response content when diagnosing a
failure.

Use CakePHP's `debug()` inside island classes during development. Interact
captures its output separately from JSON and SSE updates. Template dumps remain
in the rendered HTML. See [debugging](/guide/debugging) for viewer controls,
configuration, and the limitations of `dd()`.

## The page renders but buttons do nothing

1. Confirm the plugin and `Interact` helper are loaded.
2. Confirm `vendor/josbeir/cakephp-interact/webroot/js/runtime.js` exists.
   The standard Composer package includes it. If deployment omitted the file,
   restore the package's `webroot/` assets. For a modified local checkout, rebuild
   with `npm ci` and `npm run build`.
3. Ensure the layout outputs `$this->fetch('script')`.
4. Open `/interact/js/runtime.js` under your app's base URL. It must return
   JavaScript, not an HTML error page. Check your plugin asset symlink or asset middleware.
5. Check the console for CSP or duplicate Alpine startup errors.

For an application that already starts Alpine, disable `autoScript` and install
the npm plugin before that startup. See [installation](/installation).

## An update fails CSRF validation

Keep the CSRF middleware enabled. Confirm the page request passes through it,
the browser stores its cookie, and the update sends that cookie and the
`X-CSRF-Token` header to the same application origin.

A separate application on the same hostname may overwrite a cookie with the
same name, even on another port. The demo uses `interact_demo_csrf` to avoid this
collision. Give co-hosted applications appropriate cookie names and paths.
Refresh the page after correcting the configuration.

Apply your error middleware around the stack so exceptions receive real error
statuses. A PHP fatal-error document with HTTP 200 is not a successful update.

## A request fails even though its status is 200

Inspect the response body and content type. Interact expects its JSON response,
not a login page, a PHP warning document, or a redirect destination's HTML.

Check authentication redirects, PHP logs, and the update route. Fix the server's
error handling and underlying exception; do not treat every 200 response as a
valid island envelope.

## A template variable is undefined

Island variables come from declared props, state, `viewData()`, and reserved
`errors`. A controller's `$this->set()` does not implicitly supply island data.

Check spelling and the exact finder argument names. CakePHP reserves query option
names such as `order` and `page`; the search demo deliberately uses `sort` as its
custom finder parameter. Keep finder inputs and returned template keys explicit.
See [state and input binding](/guide/state).

<span id="an-island-alias-cannot-be-found"></span>

## An island name cannot be resolved

`render('Article/Editor')` expects `App\Island\Article\EditorIsland` in
`src/Island/Article/EditorIsland.php`. Check `App.namespace`, namespace and filename
casing, the `Island` suffix, and Composer's autoload configuration. For
`Commerce.Article/Editor`, load the Commerce plugin first. No island attribute
collection or discovery cache is required. See [Conventions](/guide/conventions).

## A helper works on the page but not inside an island

The default island renderer uses an isolated `TemplateView`, not the hosting
`AppView`'s helper configuration. Application island views are not discovered
by class name. Follow the complete [custom island view setup](/reference/rendering#add-application-helpers)
to select your view and helpers on both initial renders and update requests.

## An old page stops accepting updates

For identifying the failure and preserving local edits, see
[failures and recovery](/guide/failures).

Snapshots expire after 30 minutes by default and are bound to their issuing
session and middleware scope. Logging out, changing the session, rotating the
signing secret, or using another scope can invalidate them too.

Reload for a fresh mount. Preserve any important unsaved text first. Never
automatically replay a sensitive action after an expiry or connection failure.

## A child resets or reports duplicate keys

Use a unique stable key for each sibling island. Include record identity rather
than its current array position. Keep props stable for that key; if they change,
use a new key intentionally. See [nested islands](/guide/nesting).

## Updates repeat or the template recurses

A page island's own template must not mount the same page island. Choose either
an embedded element mounted by the host template or an `IslandView` that mounts
the declared page template. See [whole-page islands](/guide/page-islands).
