---
title: Streaming report
description: Stream report text, native CakePHP elements, and progress from one action.
---

# Streaming report

This report streams text, escaped native list items, and JSON progress from one
declared action, then retains the completed report for its final render.

Complete [installation](/installation) first. The example uses no database,
external services, or additional browser libraries. The demo uses `TestApp`;
change the namespace to `App` in your application. Run the [demo](/examples#run-the-demo)
at `/streaming`.

## Files

Create the island, template, and element at the following application paths:

- `src/Island/StreamingReportIsland.php`
- `templates/island/streaming_report.php`
- `templates/element/streaming_row.php`
- `templates/element/streaming_start.php`

::: code-group

<<< @/../tests/test_app/src/Island/StreamingReportIsland.php [Island]

<<< @/../tests/test_app/templates/island/streaming_report.php [Template]

<<< @/../tests/test_app/templates/element/streaming_row.php [Result element]

<<< @/../tests/test_app/templates/element/streaming_start.php [Starting element]

```php [Mount]
<?= $this->Interact->render('StreamingReport') ?>
```

:::

Choose **Generate report**. Each result goes to the text target and HTML list;
progress events update the Alpine indicator. The final template uses `$report`
to retain the completed output. The demo intentionally waits 600 ms between results so incremental delivery is
visible. Replace that sample pacing with your application work and emit each
result as it becomes available. The panel styles live in the demo
[`demo.css`](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/webroot/demo.css);
the streaming API does not require those styles.

See [streaming](/guide/streaming) for lifecycle, deployment, errors, renderless
completion, and the custom adapter receiving contract.
