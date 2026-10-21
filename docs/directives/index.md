---
title: Template API
description: Find each x-interact directive and the $server bridge, with practical examples and supported options.
---

# Template API

Bind a field, submit a form, refresh a status panel, or preserve a JavaScript
widget. Each page below explains one part of Interact's template API, starting
with a small example and then showing common variations.

Complete [installation](/installation) and mount [your first island](/getting-started)
before adding these bindings. All directives and `$server` belong inside an island.

| API                                       | Use it to                                                                                                      |
| ----------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| [`x-interact:model`](/directives/model)   | Bind writable PHP state to text fields, selects, radios, checkboxes, or custom widgets; stage declared uploads |
| [`x-interact:submit`](/directives/submit) | Submit a native form and protect it while the request settles                                                  |
| [`x-interact:poll`](/directives/poll)     | Refresh an island periodically, optionally while visible or until a job completes                              |
| [`x-interact:ignore`](/directives/ignore) | Preserve markup managed by a JavaScript library during server updates                                          |
| [`$server`](/reference/server)            | Read browser-visible state, call PHP actions, refresh, and display request feedback                            |

## Combine bindings in a form

For an existing island with writable `title` state and a declared `save()` action:

```php [Element]
<?= $this->Form->create(null, [
    'url' => false,
    'x-interact:submit' => '$server.call("save")',
]) ?>
    <?= $this->Form->control('title', [
        'label' => 'Title',
        'value' => $title,
        'x-interact:model' => 'title',
    ]) ?>
    <?= $this->Form->button('Save') ?>
<?= $this->Form->end() ?>
```

Typing changes a local draft. Submitting sends the latest title to PHP, calls
`save()`, and renders the response. The form's submit buttons are protected
while the submission waits or runs. Your action owns validation and persistence;
see the complete [entity editing example](/guide/forms).

## Use ordinary Alpine for presentation

Use `@click`, `:disabled`, `x-show`, and `:class` alongside Interact. For example,
a button can refresh the owning island and reflect its executing request:

```html
<button type="button" @click="$server.sync()" :disabled="$server.busy">
  Refresh
</button>
```

The [Alpine guide](/guide/alpine) explains local `x-data`, CSP expressions, and
custom JavaScript through the Composer bundle's `window.Alpine`. The
[browser reference](/reference/browser) covers scheduling and rendering behavior
shared by these APIs.
