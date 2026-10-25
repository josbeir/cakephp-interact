---
title: Realtime providers
description: Configure the optional Mercure adapter or connect an application-owned realtime service.
---

<span id="custom-realtime-providers"></span>

# Realtime providers

A provider adapter connects Interact to an external delivery service. Choose an
adapter once; application islands use the same subscriptions, publisher methods,
and template directives with every provider.

## Choose an adapter

| Adapter                                 | Setup                                                                                                                   |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Included Mercure integration            | Install the optional CakePHP package and configure a Mercure hub. See [Mercure](#mercure).                              |
| Application-owned WebSocket integration | Connect your gateway through PHP provider contracts and a browser transport. See [Custom providers](#custom-providers). |

Mercure is optional. The custom adapter contracts can connect other realtime
services too; Interact does not require their messages to arrive over SSE.
[Action streaming](/guide/streaming) uses the action's own HTTP response and needs
no realtime provider.

## Mercure

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

## Custom providers

Implement two PHP contracts and register a browser transport. The following
WebSocket example supplies all three parts; applications keep the
[ordinary realtime APIs](/guide/realtime).

| Boundary           | Contract                                       | Responsibility                                                                   |
| ------------------ | ---------------------------------------------- | -------------------------------------------------------------------------------- |
| PHP publication    | `BroadcasterInterface::publish()`              | Deliver a `Message`, preserving its ID, topic, and privacy                       |
| PHP authorization  | `SubscriptionAuthorizerInterface::authorize()` | Issue subscribe-only credentials for authorized topics until the supplied expiry |
| Browser connection | `RealtimeTransport`                            | Authenticate, receive messages, report status, reconnect, and clean up           |

`PublisherInterface` and its shortcuts are supplied by Interact. Registering a
`SubscriptionAuthorizerInterface` enables lease-based authorization; a custom
provider does not require the Mercure package or a provider-name configuration flag.

## WebSocket example

This example connects an **application-owned WebSocket gateway** with an HTTP
management API. It defines a protocol for that gateway; existing WebSocket services
have their own APIs and need corresponding changes to the adapter. Interact does
not start or implement the gateway server.

### Gateway protocol

The gateway exposes these management endpoints to PHP. Both require a server-only
management key in `Authorization: Bearer …`:

| Endpoint                  | JSON request                                          | Required behavior                                                                                                |
| ------------------------- | ----------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `POST /api/subscriptions` | `{ "topics": ["articles/1"], "expires": 1800000000 }` | Return `{ "token": "opaque-subscription-token" }` with exact subscribe-only permissions and the requested expiry |
| `POST /api/publish`       | `{ "private": true, "message": { … } }`               | Deliver the normalized message to authorized subscribers and acknowledge with a 2xx response                     |

The browser connects to `wss://gateway.example.test/socket` and sends:

```json
{
  "type": "subscribe",
  "token": "opaque-subscription-token",
  "topics": ["articles/1"]
}
```

The gateway must validate the token, restrict requested topics to its exact
permissions, and enforce its expiry for the lifetime of the connection. Only after
authorization succeeds does it send `{ "type": "subscribed" }` and start delivery.
It must prevent unauthenticated publication and unauthorized private-topic access.
The management key must never be accepted as a browser subscription token.

For a notification, forward the envelope returned by `Message::encode($topic)`:

```json
{
  "version": 1,
  "id": "message-42",
  "topic": "articles/1",
  "event": "article-updated",
  "detail": { "id": 1 }
}
```

Text and HTML messages also contain `display`; forward that field unchanged.
Preserve JSON records such as an empty `detail: {}`, message IDs, and topic names.
Use the gateway's own SDK or HTTP API when adapting this example to another server.

### PHP adapter

Create `src/Realtime/WebSocketProvider.php` in your application. The tested source
below uses the fixture namespace; replace it with your application's namespace,
for example `App\Realtime`.

<<< @/../tests/Fixture/WebSocketProvider.php

`authorize()` receives topics already selected by fresh, authorized islands. The
browser cannot add permissions to that set. The gateway issues the opaque token;
PHP passes only the socket URL, subscriber token, and authorized topics in the
`Grant`. Its `websocket` driver name must match the browser registration.

The adapter makes one management request per operation and throws on a non-2xx
response or an invalid token. Publishing uses the shared `Message` envelope, so
notification, refresh, text, and HTML modes all use the same provider.

### Register container services

For an application using the `App` namespace, add these bindings to
`Application::services()`. The gateway URLs and management key are supplied by
your environment. Load Interact through its normal plugin lifecycle.

```php
use App\Realtime\WebSocketProvider;
use Cake\Container\ContainerInterface;
use Cake\Http\Client;
use Interact\Realtime\BroadcasterInterface;
use Interact\Realtime\SubscriptionAuthorizerInterface;
use function Cake\Core\env;

public function services(ContainerInterface $container): void
{
    $container->addShared(WebSocketProvider::class, static fn(): WebSocketProvider =>
        new WebSocketProvider(
            new Client(),
            (string)env('WEBSOCKET_API_URL'),
            (string)env('WEBSOCKET_URL'),
            (string)env('WEBSOCKET_API_KEY'),
        )
    );
    foreach ([BroadcasterInterface::class, SubscriptionAuthorizerInterface::class] as $contract) {
        $container->addShared($contract, static fn(): WebSocketProvider =>
            $container->get(WebSocketProvider::class)
        );
    }
}
```

Example URLs are `WEBSOCKET_API_URL=https://gateway.example.test/api` and
`WEBSOCKET_URL=wss://gateway.example.test/socket`. The management key belongs in
server configuration. Application code continues to inject
`Interact\Realtime\PublisherInterface` and call `publishEvent()`, `publishText()`,
or `publishHtml()`; it does not construct this adapter on each call.

### Browser transport

Save this external script as `webroot/js/websocket-feed.js` and load it
[before Interact starts](/guide/alpine#custom-javascript). It registers the
`websocket` transport during the synchronous `interact:configure` event. This
startup event works with both the Composer bundle and an application-owned Alpine
installation.

<<< @/../tests/Fixture/browser/websocket-feed.js

The adapter authenticates in the first socket message. It reports `connected`
only after the gateway acknowledges authorization, then passes incoming envelopes
to `callbacks.receive()`. Interact validates the envelopes and routes them by exact
topic and event. Invalid input or a missing acknowledgement ends that connection;
closures retry with backoff. Cleanup cancels retries, removes listeners, and closes
the socket.

For application-owned modules, the equivalent registration is
`createInteract({ realtime: { transports: { websocket: transport } } })`, where
`transport` is your factory. The npm package exports `RealtimeTransport`,
`RealtimeGrant`, `TransportCallbacks`, and `PushMessage` for typed integrations.

Allow the gateway's `wss:` origin in CSP `connect-src` when your application
restricts that directive. Provider credentials stay in memory, outside island
state and browser storage.

## Lifecycle and testing

The coordinator batches island leases and shares a connection across topics in
the same tab and authorization scope. It renews grants before expiry and replaces
the transport when permissions change. Reauthorization failures and grant expiry
end access; immediate permission revocation remains provider-specific.

Transports own provider reconnect and cursor rules. A `connected` status triggers
refresh subscriptions to reconcile current application data. Notifications and
display messages are best effort; durable history and application publication
retries belong to your storage or outbox workflow.

The example's PHP tests verify management authentication, exact-topic grants,
container bindings, message encoding, invalid responses, and failed publication
without retries. Its browser transport tests use a controlled WebSocket boundary
for acknowledgement, delivery, reconnects, and cleanup. Test your actual gateway
with two independent identities, forbidden topics, expired tokens, and disconnects.

## Direct display adapters

For managed subscriptions, use a provider adapter above: Interact handles island
authorization, shared connections, and grant renewal. A direct display adapter is
an advanced alternative when the application owns a separate feed and its entire
connection lifecycle.

An adapter connects an application-owned feed, such as Mercure or a WebSocket,
to an island's display targets. Map your provider's messages to
[`$server.receiveStream()`](/reference/server#receivestream); the normal action
POST keeps its built-in SSE reader.

For example, suppose an authorized SSE feed sends `{"text":"Next result\n"}`.
Register this external script [before Interact starts](/guide/alpine#custom-javascript):

<<< @/../tests/Fixture/browser/text-feed.js

Inside the receiving island, supply an authorized subscription URL as `$feedUrl`
through `viewData()` and attach the adapter:

```php
<?php
declare(strict_types=1);
use function Cake\Core\h;
/** @var string $feedUrl */
?>
<div x-data="textFeed" data-feed-url="<?= (string)h($feedUrl) ?>">
    <pre x-interact:stream="output"></pre>
</div>
```

Each message appends literal text to this island's `output` target immediately.
The adapter supplies the current island ID, catches malformed messages, and closes
its connection when Alpine destroys the wrapper. The connection stays outside
reactive state. Use your provider's client and cleanup method in the same lifecycle;
authentication, authorized topics, and reconnection belong to that integration.

The receiver also accepts `replace: true`, HTML for explicit `.html` targets, and
`type: 'event'` messages with `name` and JSON record `detail`. Event messages reach
[`x-interact:on:event`](/directives/on) listeners in the island. The raw
`interact:stream` event remains available to advanced observers. The npm package exports `StreamMessage`
and `IslandBridge` for typed adapters; see the [message contract](/reference/server#receivestream).

These are provisional display updates. Signed state, validation, request activity,
and action results stay with normal requests; a subsequent render is authoritative.
Invalid input throws to the adapter, while delivery after island disposal is ignored.
