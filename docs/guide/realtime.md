---
title: Realtime subscriptions
description: Receive provider events, refresh authorized islands, and publish notifications or display output.
---

# Realtime subscriptions

Realtime subscriptions receive changes from other users, controllers, or jobs through
an external provider. A matching message refreshes an island, emits a browser event,
or updates an existing stream target.

Interact supports application-owned providers, including WebSocket servers.
[Custom providers](/guide/realtime-providers) use the same subscription and publishing
APIs as the bundled [Mercure integration](#configure-mercure).

Declare subscriptions in an island using `Interact\Realtime\Subscription` and
`Interact\IslandContext`:

```php
public function subscriptions(IslandContext $context): array
{
    return [Subscription::refresh('catalog', event: 'catalog-updated', private: false)];
}
```

The ordinary request lifecycle authorizes and renders each refresh. Push messages
never replace signed state or invoke PHP actions themselves.

## Subscription modes

| Declaration                                    | Behavior                                            |
| ---------------------------------------------- | --------------------------------------------------- |
| `Subscription::refresh($topic, event: $event)` | Mark the island stale and synchronize when safe     |
| `Subscription::listen($topic, event: $event)`  | Emit `interact:push` from the island root           |
| `Subscription::stream($topic, event: $event)`  | Deliver display messages to existing stream targets |

The event filter is optional. Topics match exactly; wildcard subscriptions are not
supported. All modes default to `private: true`; use `private: false` for public
delivery. The publisher must also choose public/private delivery explicitly.

Derive protected topics from authorized props/state or the current request identity.
Resource authorization belongs in `authorize()`, including during subscription
renewal. See the [complete protected example](/demo#realtime).

Declarations run after mount on initial rendering and after behavior on subsequent
updates, including renderless updates. Lazy placeholders begin subscribing only
after initialization. Each island supports up to 128 declarations; an authorization
batch supports up to 64 mounted islands and the existing 1 MiB request limit.

## Publish events, text, and HTML

Inject `Interact\Realtime\PublisherInterface` into islands, controllers, services,
or queue tasks. Publishing never requires a browser request or island instance:

```php
$publisher->publishEvent('catalog', 'catalog-updated', ['id' => 42], private: false);
$publisher->publishText('reports/42', 'report-output', 'output', "Next result\n");
$publisher->publishHtml('reports/42', 'report-output', 'rows', $renderedElement);
```

`publishText()` and `publishHtml()` take topic, event, target, content, and optional
`options: ['replace' => true, 'private' => false]`. Content appends by default and
delivery is private by default. Render `$renderedElement` with application-owned
CakePHP view/element code and escape its values normally.

For explicit message identities or application-managed retries, use
`publish($topic, $message, private: true)` with `Interact\Realtime\Message`.
`Message::event()`, `Message::text()`, and `Message::html()` share the shortcut
vocabulary. Construct `new Message('changed', detail: $data, id: $stableId)` to
retain an application-selected identity.

Messages carry a bounded ID, exact topic, event name, JSON record detail, and an
optional existing display-target message. The encoded envelope is limited to 1 MiB.
Events and targets use the existing lowercase identifier syntax.

Publish after a successful transaction commit. Publication failures throw and are
not automatically retried. Durable notification history, job results, queues, and
outbox delivery belong to application storage and services.

## Listen with Alpine

`interact:push` bubbles from the receiving island root. Inside an island template,
use a window listener filtered to that root:

```html
<div
  x-data="{ notification: '' }"
  @interact:push.window="
    $event.target === $el.closest('[data-interact-id]') &&
    (notification = $event.detail.detail.text)
  "
>
  <p role="status" x-text="notification"></p>
</div>
```

Event detail contains `id`, `topic`, `event`, and `detail`. An Alpine listener may
explicitly call `$server.call()` when application PHP behavior is needed; authorize
and validate those arguments normally. Notifications do not themselves invoke PHP.

For display subscriptions, keep existing `x-interact:stream` and
`x-interact:stream.html` targets. Their ownership and display-only restrictions
remain unchanged. A normal render replaces provisional output; retain durable
results in application storage. See [streaming](/guide/streaming).

## Refreshes and drafts

Invalidations coalesce for 100 ms. An island has at most one active automatic
refresh and one pending follow-up. Requests use the ordinary synchronization queue.

Refreshes defer while the island has local drafts, validation errors, pending
controls, uploads, queued requests, paused error recovery, or URL restoration.
They also defer during enhanced navigation and while the document is hidden.
Notifications and explicit display messages can still arrive immediately.

```html
<p role="status" x-show="$server.realtime.stale" x-cloak>
  Updates available. Your draft is preserved.
</p>
```

Only an authoritative render whose request began after the invalidation clears
stale status. Renderless actions do not clear it. Synchronization does not save;
application-owned drafts already synchronized to PHP remain application-owned.

## Connection sharing and recovery

Islands in the same Alpine installation, tab, endpoint, and CSRF scope share one
provider connection. Ten islands subscribing to three topics normally use one
provider connection for those three topics. Different tabs and authorization
scopes retain independent connections.

Adding/removing declarations renews authorization and reopens the shared provider
connection with the updated topic set. Removing the final subscriber closes it.
Compatible persisted layout islands retain their ownership across navigation.

Transports own reconnection. The bundled Mercure adapter and WebSocket example
retry transient failures with bounded backoff and jitter. Initial
connection and reconnection invalidate refresh subscriptions to reconcile current
data. Duplicate message IDs are discarded within a bounded in-memory cache.
Notifications and display streams are best effort, without exactly-once guarantees.
PHP actions are never automatically replayed.

## Authorization leases

The CSRF-protected subscription endpoint verifies separate, session/scope-bound
leases and current application authorization. Leases last five minutes and renew
after four minutes. Every renewal constructs a fresh island and runs setup,
restoration, authorization, and subscription declarations; it does not run mount,
update hooks, actions, persistence, or rendering.

Leases cannot authorize ordinary updates, uploads, or child operations. Renewal
does not extend ordinary snapshot expiry. Existing snapshot recovery may require
a remount even while a provider connection remains active. Permission changes
take effect on reauthorization or grant expiry; immediate revocation is not promised.

For protected routing scopes, connect both `InteractController::update()` and
`InteractController::subscribe()` under the application's middleware, using the
same `interactScope`. Set the helper's `subscriptionEndpoint` alongside its custom
`endpoint`. See [security](/security#application-middleware-remains-authoritative).

<span id="other-providers"></span>

## Custom providers

Implement `BroadcasterInterface` for PHP delivery and
`SubscriptionAuthorizerInterface` for expiring subscriber grants. Register a browser
transport under the grant's driver name. Interact supplies the publisher shortcuts,
authorization endpoint, leases, connection sharing, and island delivery policies.

The [custom WebSocket provider guide](/guide/realtime-providers) includes a complete
PHP adapter, CakePHP container registration, the gateway's required protocol, and a
tested browser transport with authentication, reconnection, and cleanup.

## Configure Mercure

Install the optional CakePHP 6 integration and run a Mercure 1.x hub:

```sh
composer require josbeir/cakephp-mercure:dev-cake6
```

Supply the provider configuration before loading Interact in application bootstrap.
This example configures Mercure explicitly instead of loading its default config file:

```php
use Cake\Core\Configure;
use Interact\InteractPlugin;
use Mercure\MercurePlugin;
use function Cake\Core\env;

Configure::write('Mercure', [
    'url' => env('MERCURE_URL'),
    'public_url' => env('MERCURE_PUBLIC_URL'),
    'jwt' => [
        'secret' => env('MERCURE_JWT_SECRET'),
        'issuer' => env('MERCURE_JWT_ISSUER'),
        'audience' => env('MERCURE_JWT_AUDIENCE'),
    ],
]);
Configure::write('Interact.realtime.provider', 'mercure');
$this->addPlugin(MercurePlugin::class, ['bootstrap' => false]);
$this->addPlugin(InteractPlugin::class);
```

Configure the hub with the same issuer, signing key, and resource identifier as the
token audience. `url` is the server-side publication endpoint; `public_url` is the
browser subscription endpoint. Different origins require hub CORS configuration
allowing the application origin and bearer authorization. Allow the hub in the
application's CSP `connect-src` when that directive is restricted.

The bundled browser adapter uses native fetch and subscriber-only bearer tokens.
It leaves application Mercure cookies untouched. Provider credentials remain in
memory, outside `$server.state` and persistent browser storage.

## Demo and testing

Run `npm run demo:realtime` with Docker available, then open `/realtime` in two tabs.
The launcher serves CakePHP and the built-in Mercure hub together in a pinned
FrankenPHP container, so `ngrok http 8765` can share both through one URL.
The ordinary PHP server and `composer preview` remain available without Mercure.

PHP unit tests use controlled publishing and real token generation. Runtime tests
use fake timers and controlled SSE boundaries. Realtime Playwright suites start
isolated hubs and PHP applications through the shared fixture API, failing clearly
when Docker or the pinned image is unavailable. See [contributing](/contributing).
