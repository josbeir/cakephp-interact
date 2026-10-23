<?php
declare(strict_types=1);

namespace Interact\TestSuite;

use Cake\Http\ServerRequest;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Runtime\RedirectResult;
use Interact\Runtime\RenderlessResult;
use Interact\Runtime\Result;
use Interact\Runtime\Values;
use Interact\View\TemplateView;
use Laminas\Diactoros\Uri;
use LogicException;
use PHPUnit\Framework\Assert;
use function Cake\Core\h;

/**
 * Drives an island through native rendering and signed updates in application tests.
 *
 * Only the latest result and request context survive between steps. Every interaction
 * constructs a fresh runtime, view, and island through the application's factory.
 * Middleware and browser behavior remain the responsibility of integration tests.
 */
class IslandTest
{
    protected Result $lastResult;

    /**
     * @var list<array<string, mixed>> Incremental messages from the latest interaction.
     */
    protected array $streamed = [];

    /**
     * Mount immediately using the application's existing services and declared props.
     *
     * @param string $island Conventional application/plugin name or island class reference.
     * @param array<string, mixed> $props Server-owned declared mount parameters.
     */
    public function __construct(
        protected readonly Factory $factory,
        protected IslandContext $context,
        string $island,
        array $props = [],
    ) {
        $this->lastResult = $factory->create(new TemplateView($context->request), $context)
            ->mount($island, $props, null, $context);
    }

    /**
     * Synchronize one writable property or dotted array path immediately.
     */
    public function set(string $path, mixed $value): static
    {
        return $this->fill([$path => $value]);
    }

    /**
     * Synchronize multiple writable paths together in one authorized update.
     *
     * @param array<string, mixed> $values Writable property names or dotted paths.
     */
    public function fill(array $values): static
    {
        return $this->update(['updates' => $values]);
    }

    /**
     * Invoke a declared action with typed JSON-compatible arguments.
     */
    public function call(string $action, mixed ...$args): static
    {
        return $this->update(['action' => $action, 'args' => array_values($args)]);
    }

    /**
     * Refresh server data and native markup without invoking an action.
     */
    public function sync(): static
    {
        return $this->update([]);
    }

    /**
     * Change request attributes, identity, or headers for subsequent interactions.
     *
     * The supplied request includes its session. Derive it from the original request
     * to retain the binding; changed bindings undergo normal snapshot verification.
     */
    public function withRequest(ServerRequest $request): static
    {
        $this->context = new IslandContext($request, $this->context->scope, $this->context->endpoint);

        return $this;
    }

    /**
     * Expose the latest server result for additional native PHPUnit assertions.
     */
    public function result(): Result
    {
        return $this->lastResult;
    }

    /**
     * Assert an exact transported value, preserving missing and null distinctly.
     */
    public function assertSet(string $path, mixed $expected): static
    {
        $value = $this->lastResult->state;
        foreach (Values::path($path) as $segment) {
            Assert::assertIsArray($value, sprintf('Cannot traverse island state path "%s".', $path));
            Assert::assertArrayHasKey($segment, $value, sprintf('Island state path "%s" is missing.', $path));
            /** @var mixed $value */
            $value = $value[$segment];
        }

        Assert::assertSame($expected, $value, sprintf('Unexpected island state at "%s".', $path));

        return $this;
    }

    /**
     * Assert escaped text occurs in the latest rendered HTML source.
     */
    public function assertSee(string $text): static
    {
        Assert::assertStringContainsString((string)h($text), $this->renderedHtml());

        return $this;
    }

    /**
     * Assert escaped text is absent from the latest rendered HTML source.
     */
    public function assertDontSee(string $text): static
    {
        Assert::assertStringNotContainsString((string)h($text), $this->renderedHtml());

        return $this;
    }

    /**
     * Assert validation failed, optionally requiring each named error field.
     *
     * @param list<string> $fields Normalized error paths, such as comments.0.body.
     */
    public function assertHasErrors(array $fields = []): static
    {
        Assert::assertNotEmpty($this->lastResult->errors, 'Expected island validation errors.');
        foreach ($fields as $field) {
            Assert::assertArrayHasKey($field, $this->lastResult->errors, sprintf('Missing error field "%s".', $field));
        }

        return $this;
    }

    /**
     * Assert validation succeeded globally or for the specified error fields.
     *
     * @param list<string> $fields Empty checks all response-local validation errors.
     */
    public function assertHasNoErrors(array $fields = []): static
    {
        $errors = $this->lastResult->errors;
        Assert::assertSame(
            [],
            array_intersect_key($errors, $fields === [] ? $errors : array_flip($fields)),
            'Unexpected island validation errors.',
        );

        return $this;
    }

    /**
     * Assert an event was emitted, optionally matching its complete detail strictly.
     *
     * @param array<string, mixed>|null $detail Null accepts any detail; an empty array requires empty detail.
     */
    public function assertDispatched(string $name, ?array $detail = null): static
    {
        $details = $this->eventDetails($name);
        Assert::assertNotEmpty($details, sprintf('Island event "%s" was not dispatched.', $name));
        if ($detail !== null) {
            Assert::assertContains($detail, $details, sprintf('Unexpected detail for island event "%s".', $name));
        }

        return $this;
    }

    /**
     * Assert the latest action streamed an exact target update.
     */
    public function assertStreamed(string $to, string $content, bool $replace = false, bool $html = false): static
    {
        Assert::assertContains([
            'type' => 'target',
            'to' => $to,
            'content' => $content,
            'replace' => $replace,
            'html' => $html,
        ], $this->streamed);

        return $this;
    }

    /**
     * Assert immediate progress data was emitted by the latest action.
     *
     * @param array<string, mixed> $detail Complete expected progress detail.
     */
    public function assertStreamEvent(string $name, array $detail = []): static
    {
        Assert::assertContainsEquals([
            'type' => 'event',
            'name' => $name,
            'detail' => $detail === [] ? (object)[] : $detail,
        ], $this->streamed);

        return $this;
    }

    /**
     * Assert the latest response emitted no event with this name.
     */
    public function assertNotDispatched(string $name): static
    {
        Assert::assertSame([], $this->eventDetails($name), sprintf('Unexpected island event "%s".', $name));

        return $this;
    }

    /**
     * Assert a terminal navigation instruction targets the resolved URL.
     */
    public function assertRedirect(string $url): static
    {
        Assert::assertInstanceOf(RedirectResult::class, $this->lastResult, 'Expected an island redirect.');
        Assert::assertSame($url, $this->lastResult->destination);

        return $this;
    }

    /**
     * Assert the latest interaction did not request navigation.
     */
    public function assertNoRedirect(): static
    {
        Assert::assertNotInstanceOf(RedirectResult::class, $this->lastResult, 'Unexpected island redirect.');

        return $this;
    }

    /**
     * Assert a native download was requested; this does not fetch the file.
     */
    public function assertDownload(string $url): static
    {
        Assert::assertSame($url, $this->lastResult->download, 'Unexpected island download URL.');

        return $this;
    }

    /**
     * Assert no download was requested by the latest response.
     */
    public function assertNoDownload(): static
    {
        Assert::assertNull($this->lastResult->download, 'Unexpected island download.');

        return $this;
    }

    /**
     * Assert the latest interaction produced fresh markup.
     */
    public function assertRendered(): static
    {
        Assert::assertNotInstanceOf(
            RenderlessResult::class,
            $this->lastResult,
            'The latest island response was renderless; no fresh HTML is available.',
        );
        Assert::assertNotInstanceOf(
            RedirectResult::class,
            $this->lastResult,
            'The latest island response redirected; no fresh HTML is available.',
        );

        return $this;
    }

    /**
     * Assert rendering was skipped by a renderless action or terminal redirect.
     */
    public function assertNotRendered(): static
    {
        Assert::assertTrue(
            $this->lastResult instanceof RenderlessResult || $this->lastResult instanceof RedirectResult,
            'Expected an island response without rendered HTML.',
        );

        return $this;
    }

    /**
     * Submit the latest signed identity through a fresh runtime and native view.
     *
     * @param array<string, mixed> $input State edits or an explicit action invocation.
     * @throws \LogicException When a prior redirect terminated the interaction chain.
     */
    protected function update(array $input): static
    {
        if ($this->lastResult instanceof RedirectResult) {
            throw new LogicException('A redirect ended this island interaction. Mount a new island to continue.');
        }

        $pageQuery = $this->context->request->getUri()->getQuery();
        $payload = [
            'version' => 1,
            'snapshot' => $this->lastResult->snapshot,
            'pageQuery' => '?' . $pageQuery,
            ...$input,
        ];
        $target = new Uri($this->context->endpoint);
        $request = $this->context
            ->request
            ->withMethod('POST')
            ->withUri($this->context->request->getUri()->withPath($target->getPath())->withQuery($target->getQuery()))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Interact', 'true')
            ->withHeader('Accept', 'application/json')
            ->withParsedBody($payload);
        $context = new IslandContext($request, $this->context->scope, $this->context->endpoint);
        $this->streamed = [];
        $update = $this->factory->create(new TemplateView($request), $context)->prepare($payload, $context);
        $this->lastResult = $update->run(function (array $message): void {
            $this->streamed[] = $message;
        });

        return $this;
    }

    /**
     * Refuse stale markup assertions after responses that skipped rendering.
     */
    protected function renderedHtml(): string
    {
        $this->assertRendered();

        return $this->lastResult->html;
    }

    /**
     * Retain every matching event, including repeated names with different details.
     *
     * @return list<array<string, mixed>> Complete response-local event details.
     */
    protected function eventDetails(string $name): array
    {
        return array_column(
            array_filter($this->lastResult->events, static fn(array $event): bool => $event['name'] === $name),
            'detail',
        );
    }
}
