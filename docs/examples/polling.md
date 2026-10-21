---
title: 'Job polling'
description: Poll job progress with local pause controls and PHP-driven completion.
---

# Job polling

This panel polls a simulated job until PHP reports completion. It includes
pause, resume, and restart controls.

Complete [installation](/installation) first. Copy the files below to the indicated
application locations.

No database or custom JavaScript is required. Each poll advances this simulated
job by 25%; a real application would read its authorized job status instead.

## Files

::: code-group

<<< @/../tests/docs/app/src/Island/JobProgress.php [Island]

<<< @/../tests/docs/app/templates/element/Interact/job_progress.php [Element]

```php [Mount]
<?= $this->Interact->render('job-progress') ?>
```

:::

## Behavior

The visible panel requests status every two seconds while `autoRefresh` and
`running` are true. Completion sets PHP `running` to false and stops further
requests. Resuming the local preference cannot restart a completed job; the
explicit restart action does that.

[`x-interact:poll`](/directives/poll) documents visibility, request timing, and
when edits or errors cause ticks to be skipped.
