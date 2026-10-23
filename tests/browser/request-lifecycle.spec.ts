import { expect, test } from './fixtures';
import { islandId } from './requests';
import type { RequestLifecycleDetail } from '../../resources/ts/diagnostics/types';

declare global {
  interface Window {
    requestObservations: RequestLifecycleDetail[];
  }
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.requestObservations = [];
    const record = (event: CustomEvent<RequestLifecycleDetail>) => {
      window.requestObservations.push(event.detail);
    };
    document.addEventListener('interact:request-queued', record);
    document.addEventListener('interact:request-started', record);
    document.addEventListener('interact:request-response', record);
    document.addEventListener('interact:request-applied', record);
    document.addEventListener('interact:request-finished', record);
  });
});

for (const surface of ['Composer', 'application-owned Alpine']) {
  test.describe(surface, () => {
    test.beforeEach(async ({ page }) => {
      if (surface === 'application-owned Alpine') {
        await page.route('**/interact/js/runtime.js', (route) =>
          route.fulfill({
            path: 'tests/test_app/webroot/js/configuration.js',
            contentType: 'text/javascript',
          }),
        );
      }
    });

    test('observes rendered, renderless, and validation results under CSP without exposing drafts', async ({
      page,
    }) => {
      const errors: string[] = [];
      page.on('pageerror', (error) => errors.push(error.message));
      await page.goto('/renderless');
      const region = page.getByRole('region', {
        name: 'Renderless draft editor',
      });
      const title = region.getByLabel('Draft title');
      const owner = await islandId(title);
      await title.fill('private-observation-draft');
      await title.press('Enter');
      await expect(region.getByTestId('live-saves')).toHaveText('1');
      await region
        .getByRole('button', { name: 'Refresh view', exact: true })
        .click();
      await expect(region.getByTestId('php-saves')).toHaveText('1');
      await title.fill('');
      await title.press('Enter');
      await expect(
        region.getByRole('alert').filter({ hasText: 'Enter a title.' }),
      ).toBeVisible();
      await expect
        .poll(() =>
          page.evaluate(
            (id) =>
              window.requestObservations.filter(
                (detail) =>
                  detail.islandId === id && detail.phase === 'finished',
              ).length,
            owner,
          ),
        )
        .toBe(3);
      const records = await page.evaluate(
        (id) =>
          window.requestObservations.filter((detail) => detail.islandId === id),
        owner,
      );
      const completed = records.filter((detail) => detail.phase === 'finished');
      expect(completed.map((detail) => detail.outcome)).toEqual([
        'success',
        'success',
        'validation',
      ]);
      expect(
        records
          .filter((detail) => detail.phase === 'applied')
          .map((detail) => detail.rendered),
      ).toEqual([false, true, true]);
      for (const detail of completed) {
        expect(
          records
            .filter((record) => record.requestId === detail.requestId)
            .map((record) => record.phase),
        ).toEqual(['queued', 'started', 'response', 'applied', 'finished']);
        expect(detail.timings.totalMs).toBeGreaterThanOrEqual(0);
      }
      expect(JSON.stringify(records)).not.toContain(
        'private-observation-draft',
      );
      expect(errors).toEqual([]);
    });

    test('keeps cancellation observable after Alpine removes an island', async ({
      page,
    }) => {
      await page.goto('/renderless');
      const region = page.getByRole('region', {
        name: 'Renderless draft editor',
      });
      const owner = await islandId(region.getByLabel('Draft title'));
      await region.getByLabel('Draft title').fill('Remove after starting');
      await region.locator('form').evaluate((form) => {
        if (!(form instanceof HTMLFormElement)) throw new Error('Missing form');
        const root = form.closest<HTMLElement>('[data-interact-id]');
        if (!root) throw new Error('Missing island');
        root.addEventListener('interact:request-started', () => root.remove(), {
          once: true,
        });
        form.requestSubmit();
      });
      await expect(region.getByLabel('Draft title')).toHaveCount(0);
      await expect
        .poll(() =>
          page.evaluate(
            (id) =>
              window.requestObservations.find(
                (detail) =>
                  detail.islandId === id && detail.phase === 'finished',
              )?.phase === 'finished',
            owner,
          ),
        )
        .toBe(true);
      const completed = await page.evaluate(
        (id) =>
          window.requestObservations.filter(
            (detail) => detail.islandId === id && detail.phase === 'finished',
          ),
        owner,
      );
      expect(completed).toHaveLength(1);
      expect(completed[0]).toMatchObject({
        outcome: 'cancelled',
        failure: null,
      });
    });

    test('observes one lifecycle for a finite streaming action', async ({
      page,
    }) => {
      await page.goto('/streaming');
      await page.getByRole('button', { name: 'Generate report' }).click();
      await expect(page.getByRole('status')).toHaveText('Report complete');
      const records = await page.evaluate(() => window.requestObservations);
      expect(
        records.filter((detail) => detail.phase === 'finished'),
      ).toHaveLength(1);
      expect(records.map((detail) => detail.phase)).toEqual([
        'queued',
        'started',
        'response',
        'applied',
        'finished',
      ]);
      expect(records.at(-1)).toMatchObject({
        outcome: 'success',
        kind: 'action',
        httpStatus: 200,
      });
    });
  });
}
