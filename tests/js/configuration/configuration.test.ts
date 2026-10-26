import { afterEach, expect, it, vi } from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import { createInteract } from '../../../resources/ts/index';
import {
  defaultTiming,
  timingDefaults,
} from '../../../resources/ts/configuration';
import { modelBinding } from '../../../resources/ts/bindings/model';
import { pollBinding } from '../../../resources/ts/bindings/poll';
import { NavigationActivity } from '../../../resources/ts/navigation/activity';
import { RequestActivity } from '../../../resources/ts/scheduling/request-activity';
import type { Fetcher } from '../../../resources/ts/types';

afterEach(() => {
  Alpine.destroyTree(document.body);
  window.dispatchEvent(new PageTransitionEvent('pagehide'));
  document.body.replaceChildren();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

it('normalizes immutable JavaScript defaults and preserves zero without changing unrelated timings', () => {
  expect(timingDefaults()).toEqual(defaultTiming);
  const defaults = timingDefaults({
    defaults: { debounce: 0, loadingDelay: 0 },
  });
  expect(defaults).toEqual({
    debounce: 0,
    loadingDelay: 0,
    pollInterval: 5000,
  });
  expect(Object.isFrozen(defaults)).toBe(true);
  expect(defaultTiming.debounce).toBe(250);
  expect(modelBinding('value', ['live'], 400).delay).toBe(400);
  expect(modelBinding('value', ['live', 'debounce', '0ms'], 400).delay).toBe(0);
  expect(modelBinding('value', [], 400).delay).toBe(250);
  expect(pollBinding([], 1000).interval).toBe(1000);
  expect(pollBinding(['2s'], 1000).interval).toBe(2000);
});

it.each([
  null,
  [],
  false,
  { debounce: 20 },
  { defaults: null },
  { defaults: [] },
  { defaults: { unknown: 10 } },
  { defaults: { debounce: null } },
  { defaults: { debounce: '400' } },
  { defaults: { debounce: -1 } },
  { defaults: { debounce: 60001 } },
  { defaults: { loadingDelay: 1.5 } },
  { defaults: { loadingDelay: Number.NaN } },
  { defaults: { loadingDelay: Number.POSITIVE_INFINITY } },
  { defaults: { pollInterval: 0 } },
  { defaults: { pollInterval: 2147483648 } },
])('rejects malformed configuration: %j', (options) => {
  expect(() => timingDefaults(options)).toThrow('Interact');
});

it('uses the configured feedback default for islands and navigation while explicit queries win', () => {
  vi.useFakeTimers();
  const activity = new RequestActivity(Alpine.reactive, 75);
  activity.update([{ action: 'save', args: [] }]);
  const navigation = new NavigationActivity(Alpine.reactive, 75);
  navigation.start();
  expect(activity.loading()).toBe(false);
  expect(navigation.store.loading()).toBe(false);
  expect(navigation.store.loading({ delay: 0 })).toBe(true);
  vi.advanceTimersByTime(74);
  expect(activity.loading()).toBe(false);
  vi.advanceTimersByTime(1);
  expect(activity.loading()).toBe(true);
  expect(navigation.store.loading()).toBe(true);
  expect(activity.loading({ delay: 200 })).toBe(false);
  activity.dispose();
  navigation.finish();
  expect(vi.getTimerCount()).toBe(0);
});

function root(id: string, content: string): HTMLElement {
  const element = document.createElement('div');
  element.setAttribute('x-data', 'interact');
  Object.assign(element.dataset, {
    interactId: id,
    interactState: '{"value":""}',
    interactWritable: '{"value":{"type":"string","nullable":false}}',
    interactSnapshot: 'signed',
    interactEndpoint: '/_interact/update',
  });
  element.innerHTML = content;
  return element;
}

it('installs configured control, polling, and child timings once without affecting upload bindings', async () => {
  vi.useFakeTimers();
  expect(() =>
    createInteract({ defaults: { debounce: -1 } })(Alpine),
  ).toThrow();
  createInteract({
    defaults: { debounce: 400, pollInterval: 1000, loadingDelay: 75 },
  })(Alpine);
  createInteract({ defaults: { debounce: 1 } })(Alpine);
  const native = root(
    'native',
    '<input aria-label="Native" x-interact:model.live="value">',
  );
  const parent = root('parent', '');
  const child = root(
    'child',
    '<input aria-label="Child" x-interact:model="value">',
  );
  child.dataset.interactRelation = JSON.stringify({
    parentId: 'parent',
    models: { value: { path: 'value', timing: 'live' } },
  });
  parent.append(child);
  const explicitParent = root('explicit-parent', '');
  const explicitChild = root(
    'explicit-child',
    '<input aria-label="Explicit" x-interact:model="value">',
  );
  explicitChild.dataset.interactRelation = JSON.stringify({
    parentId: 'explicit-parent',
    models: { value: { path: 'value', timing: 'live', delay: 250 } },
  });
  explicitParent.append(explicitChild);
  const polling = root('polling', '');
  polling.setAttribute('x-interact:poll', '');
  const upload = root(
    'upload',
    '<input type="file" x-interact:model="document">',
  );
  upload.dataset.interactUploads =
    '{"document":{"multiple":false,"maxSize":1024,"maxFiles":1,"files":[]}}';
  document.body.append(native, parent, explicitParent, polling, upload);
  const fetcher = vi.fn<Fetcher>(() => new Promise<Response>(() => {}));
  vi.stubGlobal('fetch', fetcher);
  Alpine.initTree(document.body);
  await vi.advanceTimersByTimeAsync(0);
  for (const input of document.querySelectorAll<HTMLInputElement>(
    'input:not([type=file])',
  )) {
    input.value = 'draft';
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }
  await vi.advanceTimersByTimeAsync(249);
  expect(fetcher).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(1);
  expect(fetcher).toHaveBeenCalledTimes(1);
  await vi.advanceTimersByTimeAsync(149);
  expect(fetcher).toHaveBeenCalledTimes(1);
  await vi.advanceTimersByTimeAsync(1);
  expect(fetcher).toHaveBeenCalledTimes(3);
  await vi.advanceTimersByTimeAsync(600);
  expect(fetcher).toHaveBeenCalledTimes(4);
  expect(upload.querySelector('input')).not.toBeNull();
});
