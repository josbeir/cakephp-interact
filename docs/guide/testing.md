---
title: Testing islands
description: Test island state, actions, validation, native templates, and response effects with CakePHP and PHPUnit.
---

# Testing islands

`IslandTestTrait` tests an island through its real lifecycle, signed snapshots,
application services, and native templates. Add it to an existing CakePHP
`TestCase`; each interaction constructs a fresh island.

## Test your first island

This test uses the counter class and template from [Quickstart](/getting-started).
Complete the PHP plugin setup in [Installation](/installation) and use your
application's normal CakePHP test bootstrap with PHPUnit 12.5 or later in the
12.x series. These server-side tests do not need browser assets or JavaScript.

Create `tests/TestCase/Island/CounterTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Island;

use App\Island\CounterIsland;
use Cake\TestSuite\TestCase;
use Interact\TestSuite\IslandTestTrait;

/**
 * Tests the counter's declared action and server-rendered output.
 */
class CounterTest extends TestCase
{
    use IslandTestTrait;

    public function testIncrement(): void
    {
        $this->testIsland(CounterIsland::class)
            ->assertSet('count', 0)
            ->call('increment')
            ->assertSet('count', 1)
            ->assertSee('Count: 1');
    }
}
```

Run it with your application's PHPUnit command:

```sh
vendor/bin/phpunit tests/TestCase/Island/CounterTest.php
```

`testIsland($island, props: [], request: null)` accepts the same conventional
names and class references as the mount helper, including loaded plugin names
such as `Commerce.Article/Editor`. Only declared props can be supplied at mount.
An ordinary eager mount runs immediately and produces the initial assertions'
result. Nonstandard classes still require normal application registration.

## Edit state and call actions

`set()` immediately sends one writable property or dotted path through the state
service. `fill()` sends multiple paths together in one update. Both run normal
authorization and `updated()` hooks before rendering:

```php
$island = $this->testIsland('Article/Form')
    ->fill([
        'data.title' => 'An article',
        'data.comments.0.body' => 'First comment',
    ])
    ->assertSet('data.comments.0.body', 'First comment')
    ->call('save')
    ->assertHasNoErrors();
```

This fragment assumes an application `Article/Form` island with writable `data`
state and a declared `save` action, as in the [article form](/examples/article-form).
Each `set()`, `fill()`, `call()`, and `sync()` is a separate interaction.
`call($action, ...$args)` accepts typed JSON-compatible arguments, and `sync()`
refreshes without an action. Locked state and props cannot be edited through
`set()` or `fill()`.

The helper retains the latest signed snapshot, not a live island object. It
does not call `mount()` again or assign properties directly on a retained object.
Exceptions from invalid writes, undeclared actions, authorization, and application
code propagate to PHPUnit. Use normal `expectException()` assertions.

## Assertions

Assertions return the same fluent test object and inspect the latest result:

| Assertion                                     | Behavior                                                                      |
| --------------------------------------------- | ----------------------------------------------------------------------------- |
| `assertSet($path, $expected)`                 | Strict equality for transported props or state; supports dotted paths.        |
| `assertSee($text)` / `assertDontSee($text)`   | Search for escaped text in fresh rendered HTML source.                        |
| `assertHasErrors($fields = [])`               | Require errors, optionally requiring each listed normalized field path.       |
| `assertHasNoErrors($fields = [])`             | Require no errors globally, or none on the listed paths.                      |
| `assertDispatched($name, $detail = null)`     | Require a response-local event, optionally matching complete detail strictly. |
| `assertNotDispatched($name)`                  | Require no event of that name in the latest response.                         |
| `assertRedirect($url)` / `assertNoRedirect()` | Check the resolved navigation instruction.                                    |
| `assertDownload($url)` / `assertNoDownload()` | Check the requested native download URL.                                      |
| `assertRendered()` / `assertNotRendered()`    | Check whether the latest interaction produced fresh markup.                   |

`assertSet()` distinguishes absent paths from `null`, `false`, zero, and empty
strings. Event detail `null` accepts any detail; `[]` requires empty detail.
Repeated event names can match any complete detail from that response. Errors
and events do not accumulate between interactions.

`assertSee()` checks escaped HTML source, including attributes; it does not
evaluate visibility, JavaScript, or text split by markup. Inspect raw markup with
`result()->html` and native PHPUnit assertions when needed.

After a renderless action, state and effect assertions still work:

```php
$island->call('saveDraft')
    ->assertNotRendered()
    ->assertHasNoErrors()
    ->assertSet('saved', true);
```

This assumes a declared `saveDraft` action using `#[Action(render: false)]` or
`skipRender()`. Validation errors and subsequent cleanup can still force a render.
Both HTML assertions fail clearly when the latest response is renderless or a
redirect. They never inspect an earlier render. Redirects end the chain; mount a
new test island for the destination. A download assertion checks the instruction
only, without fetching a file.

`result()` returns the latest native `Result`, including normalized errors, events,
state, HTML, and snapshot. Use it for additional PHPUnit assertions. If an
interaction throws, it leaves the last successfully completed result unchanged.

## Requests and authorization

Supply a `Cake\Http\ServerRequest` to establish the identity and attributes your
application normally reads in `authorize()`:

```php
$request = (new ServerRequest(['url' => 'http://localhost/articles/7']))
    ->withAttribute('identity', $identity);

$island = $this->testIsland('Article/Editor', props: ['articleId' => 7], request: $request);
```

Import `Cake\Http\ServerRequest`. `$identity` is your application-supplied identity
object or value; the helper does not replace authentication or authorization.
The default request is an anonymous GET to `http://localhost/`.

Use `withRequest()` before the next interaction to test a permission change:

```php
$this->expectException(ForbiddenException::class);
$island->withRequest($request->withAttribute('identity', $revokedIdentity))
    ->call('save');
```

Import `Cake\Http\Exception\ForbiddenException`. This assumes `authorize()` rejects
the revoked application identity. The replacement request includes its session;
derive it from the original request to retain that binding. A changed session
is subject to normal snapshot verification. Actions and rendering do not run
after authorization fails.

Pass query parameters and routing defaults through the initial request when
testing URL-bound state or partial CakePHP redirect arrays. Subsequent steps
receive a POST request to the configured update endpoint. `withRequest()` changes
the next request context without mounting again.

## Application services and fixtures

The trait uses CakePHP's `App.namespace` convention to bootstrap the application
once per test. It loads plugin services and routes, resolves the application's
configured `Factory`, and uses its views. No custom factory setup is required.
CakePHP fixtures and Table finders continue working normally; assert database
changes with the ORM after calling an island action.

The trait supplies CakePHP's `configApplication()` and `mockService()` support.
Configure an alternate application or service mocks before the first
`testIsland()` call. Subsequent islands in that test share the configured
application services. The trait also composes with `IntegrationTestTrait`.

Application services, mocks, plugin collections, routes, and event-manager changes
are released or restored during test cleanup. Existing CakePHP test setup and
teardown remain in place.

For a custom endpoint or middleware scope, override this method in your test case
using imports `Interact\IslandContext` and `Cake\Http\ServerRequest`:

```php
protected function islandTestContext(ServerRequest $request): IslandContext
{
    return new IslandContext($request, 'admin', '/admin/_interact/update');
}
```

For explicit service injection, construct
`new Interact\TestSuite\IslandTest($factory, $context, $island, $props)` using an
application-configured `Factory` and `IslandContext`.

## Choose the test layer

Island tests exercise PHP behavior, real templates, declared state permissions,
and signed state restoration. They do not execute HTTP middleware or the hosting
controller. Keep CSRF, authentication middleware, cookies, and endpoint status
assertions in CakePHP integration tests. Use Playwright for DOM morphing, focus,
client scheduling, navigation, and upload transfer behavior.

Dedicated upload, deferred-initialization, and browser-history drivers are not
part of this API. Existing integration and browser tests remain appropriate for
those flows. The helper does not create browser assets or require JavaScript.

## Streaming assertions

Streaming actions run synchronously with a collecting sink in `IslandTest`; no
bytes are emitted. `assertStreamed($to, $content, replace: false, html: false)`
matches an exact incremental target update.
`assertStreamEvent($name, $detail = [])` matches immediate progress data. Both
inspect only the latest interaction and work alongside ordinary state, rendering,
validation, and event assertions. Browser tests should also verify delivery before
action completion through the actual web server.
