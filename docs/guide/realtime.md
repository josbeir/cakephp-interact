---
title: Realtime subscriptions
description: Build a two-tab notification, refresh server-rendered content, and publish text or HTML through any provider adapter.
---

# Realtime subscriptions

Realtime subscriptions receive publications from other requests, users, or jobs
through a configured provider adapter. One publication can update every subscribed
tab. For incremental output during a single action, use [streaming](/guide/streaming).

## Before you start

Complete [installation](/installation), then [configure a provider adapter](/guide/realtime-providers).
Interact includes an **optional Mercure integration** and supports application-owned
adapters, including WebSocket gateways. The examples below work with either; no
provider-specific code or separate JavaScript file belongs in the island.

<span id="listen-with-alpine"></span>

## Send a notification to two tabs

Create `src/Island/NoticeIsland.php` and `templates/island/notice.php`, then put the
mount call in an existing page template:

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\PublisherInterface;
use Interact\Realtime\Subscription;

/**
 * Sends a public demonstration notification to every subscribed tab.
 */
class NoticeIsland extends Island
{
    public function __construct(protected readonly PublisherInterface $publisher)
    {
    }

    /**
     * Listen for notifications on the shared demonstration topic.
     *
     * @return list<Subscription>
     */
    public function subscriptions(IslandContext $context): array
    {
        return [
            Subscription::listen('demo/notifications', event: 'notification', private: false),
        ];
    }

    /**
     * Publish without replacing the local notification markup.
     */
    #[Action(render: false)]
    public function notify(): void
    {
        $this->publisher->publishEvent(
            'demo/notifications',
            'notification',
            ['text' => 'Notification sent at ' . date('H:i:s')],
            private: false,
        );
    }
}
```

```php [Template]
<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
?>
<div
    x-data="{ notification: '' }"
    x-interact:on:notification="notification = $event.detail.text"
>
    <p>Connection: <span x-text="$server.realtime.status"></span></p>
    <?= $this->Form->button('Send notification', [
        'type' => 'button',
        '@click' => '$server.call("notify")',
        ':disabled' => '$server.pending("notify") || $server.realtime.status !== "connected"',
    ]) ?>
    <p role="status" x-text="notification || 'No notifications yet.'">No notifications yet.</p>
</div>
```

```php [Mount]
<?= $this->Interact->render('Notice') ?>
```

:::

Open the page in two tabs. When both show **connected**, click **Send notification**
in either tab. Both show the sent time without a page reload. The button calls
`notify()`, the publisher sends the message, and each subscribed island evaluates
its `x-interact:on:notification` expression.

This example deliberately uses a public topic: everyone subscribed to
`demo/notifications` can receive it. [Private topics](#private-topics) require
application-owned access control.

### Topic, event, and target

| Name   | Meaning                                                  | In these examples              |
| ------ | -------------------------------------------------------- | ------------------------------ |
| Topic  | Which islands can receive a publication                  | `demo/notifications`           |
| Event  | Which kind of message a subscription or listener handles | `notification`                 |
| Target | Which text/HTML region receives display output           | `output` in the examples below |

The publisher and subscription must agree on the topic and event. A listener
receives the application payload directly in `$event.detail`; Interact handles
island ownership and cleanup. See [`x-interact:on`](/directives/on) for its options.

## Refresh server-rendered HTML

Use `Subscription::refresh()` when PHP should render updated content. This
standalone clock example needs no database and no custom event listener.
Create `src/Island/ClockIsland.php` and `templates/island/clock.php`, then mount it:

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\PublisherInterface;
use Interact\Realtime\Subscription;

/**
 * Refreshes a server-rendered timestamp in every subscribed tab.
 */
class ClockIsland extends Island
{
    public function __construct(protected readonly PublisherInterface $publisher)
    {
    }

    /**
     * Fetch fresh HTML when another tab requests a clock update.
     *
     * @return list<Subscription>
     */
    public function subscriptions(IslandContext $context): array
    {
        return [
            Subscription::refresh('demo/clock', event: 'clock-updated', private: false),
        ];
    }

    /**
     * Compute the timestamp on each render rather than storing it in a snapshot.
     *
     * @return array{updatedAt: string}
     */
    public function viewData(): array
    {
        return ['updatedAt' => date('H:i:s')];
    }

    /**
     * Signal all readers without performing a local render in the action response.
     */
    #[Action(render: false)]
    public function refreshReaders(): void
    {
        $this->publisher->publishEvent('demo/clock', 'clock-updated', private: false);
    }
}
```

```php [Template]
<?php
declare(strict_types=1);
use function Cake\Core\h;
/**
 * @var \Interact\View\TemplateView $this
 * @var string $updatedAt
 */
?>
<div>
    <p>Connection: <span x-text="$server.realtime.status"></span></p>
    <p>Last rendered by PHP: <time><?= h($updatedAt) ?></time></p>
    <?= $this->Form->button('Refresh readers', [
        'type' => 'button',
        '@click' => '$server.call("refreshReaders")',
        ':disabled' => '$server.pending("refreshReaders") || $server.realtime.status !== "connected"',
    ]) ?>
</div>
```

```php [Mount]
<?= $this->Interact->render('Clock') ?>
```

:::

Open two tabs and wait for **connected**. After a few seconds, click **Refresh readers**.
Both tabs fetch fresh HTML and show a new timestamp. `refreshReaders()` publishes
the signal; `Subscription::refresh()` schedules the ordinary PHP render.

In an application, `viewData()` can read current records through your Table finders.
Publish the change after saving; synchronization itself never saves data. The
[chat feed](/demo#realtime) demonstrates persisted history.

## Append text

Use `Subscription::stream()` to deliver text straight into a named display target.
Create `src/Island/TextFeedIsland.php` and `templates/island/text_feed.php`:

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\PublisherInterface;
use Interact\Realtime\Subscription;

/**
 * Publishes a line of text to every subscribed tab's output target.
 */
class TextFeedIsland extends Island
{
    public function __construct(protected readonly PublisherInterface $publisher)
    {
    }

    /**
     * Receive provisional text output on the shared demonstration topic.
     *
     * @return list<Subscription>
     */
    public function subscriptions(IslandContext $context): array
    {
        return [Subscription::stream('demo/feed', event: 'line', private: false)];
    }

    /**
     * Append a line without rendering over the provisional output.
     */
    #[Action(render: false)]
    public function appendOutput(): void
    {
        $this->publisher->publishText(
            'demo/feed',
            'line',
            'output',
            'Received at ' . date('H:i:s') . "\n",
            options: ['private' => false],
        );
    }
}
```

```php [Template]
<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
?>
<div>
    <p>Connection: <span x-text="$server.realtime.status"></span></p>
    <?= $this->Form->button('Append a line', [
        'type' => 'button',
        '@click' => '$server.call("appendOutput")',
        ':disabled' => '$server.pending("appendOutput") || $server.realtime.status !== "connected"',
    ]) ?>
    <pre x-interact:stream="output"></pre>
</div>
```

```php [Mount]
<?= $this->Interact->render('TextFeed') ?>
```

:::

When both tabs show **connected**, click **Append a line**. Each click adds a line
in both tabs. The topic is `demo/feed`, the event is `line`, and the third
`publishText()` argument matches `x-interact:stream="output"`.

## Replace HTML

Use `publishHtml()` with an explicit `.html` target for formatted output. Create
`src/Island/ResultIsland.php` and `templates/island/result.php`:

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Island;
use Interact\IslandContext;
use Interact\Realtime\PublisherInterface;
use Interact\Realtime\Subscription;

/**
 * Replaces a display target with trusted application HTML in every subscribed tab.
 */
class ResultIsland extends Island
{
    public function __construct(protected readonly PublisherInterface $publisher)
    {
    }

    /**
     * Receive formatted results on a shared public topic.
     *
     * @return list<Subscription>
     */
    public function subscriptions(IslandContext $context): array
    {
        return [Subscription::stream('demo/results', event: 'result', private: false)];
    }

    /**
     * Publish the result without replacing the island template.
     */
    #[Action(render: false)]
    public function replaceOutput(): void
    {
        $this->publisher->publishHtml(
            'demo/results',
            'result',
            'output',
            '<strong>Report ready.</strong>',
            options: ['replace' => true, 'private' => false],
        );
    }
}
```

```php [Template]
<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
?>
<div>
    <p>Connection: <span x-text="$server.realtime.status"></span></p>
    <?= $this->Form->button('Show result', [
        'type' => 'button',
        '@click' => '$server.call("replaceOutput")',
        ':disabled' => '$server.pending("replaceOutput") || $server.realtime.status !== "connected"',
    ]) ?>
    <div x-interact:stream.html="output"></div>
</div>
```

```php [Mount]
<?= $this->Interact->render('Result') ?>
```

:::

When both tabs show **connected**, click **Show result**. Both display the formatted
result. `.html` must match `publishHtml()`; a mode mismatch is rejected.
`publishText()` keeps HTML characters as literal text. For dynamic HTML, render
an application-owned CakePHP element and escape its values normally.

Text and HTML publications are provisional display updates. A normal island
render or page reload replaces them; keep durable results in application storage.
Targets must contain display-only markup; see [`x-interact:stream`](/directives/stream).

## Subscription modes

| Declaration                                    | Use it to                                  |
| ---------------------------------------------- | ------------------------------------------ |
| `Subscription::listen($topic, event: $event)`  | React in Alpine with `x-interact:on:event` |
| `Subscription::refresh($topic, event: $event)` | Fetch fresh PHP-rendered HTML when safe    |
| `Subscription::stream($topic, event: $event)`  | Append or replace text/HTML targets        |

Topics match exactly; wildcard subscriptions are not supported. Omit `event` to
receive every event on the topic. Declarations run after mount and on subsequent
updates; lazy islands subscribe after initialization.

## Publish events, text, and HTML

`PublisherInterface` can also be injected into controllers, services, and queue tasks.
Use `publishEvent($topic, $event, $detail, private: ...)` for notifications or
refresh signals. `publishText()` and `publishHtml()` take topic, event, target,
content, and `options: ['replace' => ..., 'private' => ...]`. Display publications
append by default; all publication methods default to private delivery.

Publish after a successful transaction commit. Failures throw and are not
automatically retried; application queues and outboxes own durable delivery.

::: details Message identities and limits

For an application-managed message ID, call `publish($topic, $message, private: true)`
with `Interact\Realtime\Message`. `Message::event()`, `Message::text()`, and
`Message::html()` construct messages; `new Message('changed', detail: $data, id: $id)`
retains an application-selected identity. Reuse that identity in your own retry workflow.

Topics are bounded exact identifiers. Events and targets use lowercase names
matching `[a-z][a-z0-9:-]*`. Each encoded message is limited to 1 MiB, including
its ID, topic, event, JSON record detail, and optional display output. Each island
supports 128 declarations; an authorization batch supports 64 islands and a 1 MiB request.

:::

## Private topics

Subscriptions and publications default to `private: true`. The public examples
above explicitly set `private: false` at both ends. For user or resource-specific
messages, derive the topic from an authorized resource or current request identity
and enforce access in `authorize()` on the receiving island. A topic's name is not
an access check. See the [session-private chat example](/demo#realtime).

## Refreshes and drafts

Automatic refreshes wait while an island has unsent drafts, validation errors, or
work in progress. They also wait while the document is hidden. Event listeners
and explicit display updates can still run immediately.

`$server.realtime.stale` indicates that a refresh is waiting. Use ordinary Alpine
presentation if the application needs to show it; see [`$server.realtime`](/reference/server#realtime).

::: details Refresh scheduling

Invalidations coalesce for 100 ms, with at most one active automatic refresh and
one pending follow-up per island. Refreshes use the ordinary synchronization queue.
Pending controls, uploads, queued requests, paused recovery, URL restoration, and
enhanced navigation also defer refreshing. Only an authoritative render whose
request began after the invalidation clears stale status; a renderless action does not.

:::

## Connection sharing and recovery

Islands in the same tab and authorization scope share one provider connection
across their topics. Removing the final subscriber closes it. Different tabs
retain separate connections. Reconnection can refresh saved content, but notifications
and display messages are best effort; store anything that must survive missed messages.

::: details Connection lifecycle

The sharing scope is one Alpine installation, subscription endpoint, and CSRF
context. Changing declarations renews authorization and reopens the connection;
compatible persisted layout islands retain ownership across navigation.

Transports own reconnection. The included Mercure adapter and custom WebSocket
example use bounded backoff and jitter. Connecting or reconnecting invalidates
refresh subscriptions. Duplicate message IDs are discarded in a bounded in-memory
cache. PHP actions are never automatically replayed.

:::

## Authorization leases

Interact checks current island authorization when granting or renewing subscriptions.
Keep protected access in `authorize()`; initial authorization alone is insufficient.

::: details Lease lifecycle and custom middleware scopes

The CSRF-protected subscription endpoint verifies separate session/scope-bound
leases. Leases last five minutes and renew after four minutes. Renewal constructs
fresh islands and runs setup, restoration, authorization, and subscription declarations;
it does not run mount, actions, update hooks, persistence, or rendering.

Leases neither authorize ordinary updates nor extend action snapshot expiry.
Permission changes take effect on reauthorization or grant expiry; immediate
revocation is not promised. Snapshot recovery may require remounting while a
provider connection is still active.

For protected routing scopes, connect `InteractController::update()` and
`InteractController::subscribe()` under the same middleware and `interactScope`.
Set the helper's `subscriptionEndpoint` with its custom `endpoint`. Signed identity
preserves the subscription endpoint through updates, lazy initialization, and nested
mounts; browser payloads cannot replace it. See [security](/security#application-middleware-remains-authoritative).

:::

<span id="other-providers"></span>

## Custom providers

The [Advanced providers guide](/guide/realtime-providers#custom-providers) shows
an application-owned WebSocket gateway, PHP service registration, and browser
transport. The application-facing examples on this page stay the same.

<span id="configure-mercure"></span>

## Provider setup

Choose the [optional Mercure adapter](/guide/realtime-providers#mercure) or an
[application-owned provider adapter](/guide/realtime-providers#custom-providers).
Provider configuration belongs in application bootstrap/services, separately from
island subscriptions and template listeners.

## Demo and testing

The [chat demo](/demo#realtime) combines saved history, private notifications, text,
HTML, and progress in one application. Its page links the tested source and setup
instructions. See [contributing](/contributing) for the shared browser-test launcher.
