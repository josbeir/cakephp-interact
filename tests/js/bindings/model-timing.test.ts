import { afterEach, expect, it, vi } from 'vite-plus/test';
import { mountClient, destroyBindings } from '../support/mount';
import type { IslandClient } from '../../../resources/ts/client';
import type { Json } from '../../../resources/ts/types';

const clients: IslandClient[] = [];

function setup(markup: string, value: Json, scope: string, modifier = '.live') {
  const root = document.createElement('div');
  Object.assign(root.dataset, {
    interactId: 'model-timing',
    interactSnapshot: 'signed',
    interactEndpoint: '/_interact/update',
    interactState: JSON.stringify({ data: { value } }),
    interactWritable: '{"data":{"type":"array","nullable":false}}',
  });
  root.innerHTML =
    scope === 'form'
      ? `<form x-interact:model${modifier}="data">${markup.replace('BINDING', 'name="value"')}</form>`
      : markup.replace('BINDING', `x-interact:model${modifier}="data.value"`);
  document.body.append(root);
  const fetcher = vi.fn().mockResolvedValue(new Response('', { status: 503 }));
  const client = mountClient(root, fetcher);
  clients.push(client);
  const control = root.querySelector('input,select,textarea');
  if (
    !(control instanceof HTMLInputElement) &&
    !(control instanceof HTMLSelectElement) &&
    !(control instanceof HTMLTextAreaElement)
  )
    throw new Error('Missing model control.');
  const edit = () => {
    if (
      control instanceof HTMLInputElement &&
      ['checkbox', 'radio'].includes(control.type)
    )
      control.checked = true;
    else control.value = 'b';
    control.dispatchEvent(new Event('input', { bubbles: true }));
    control.dispatchEvent(new Event('change', { bubbles: true }));
  };
  return { client, fetcher, control, edit };
}

afterEach(() => {
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  document.body.innerHTML = '';
  vi.useRealTimers();
});

const selections: {
  name: string;
  markup: string;
  initial: Json;
  next: Json;
}[] = [
  {
    name: 'select',
    markup:
      '<select BINDING><option value="a">A</option><option value="b">B</option></select>',
    initial: 'a',
    next: 'b',
  },
  {
    name: 'multiple select',
    markup:
      '<select multiple BINDING><option value="a">A</option><option value="b">B</option></select>',
    initial: [],
    next: ['b'],
  },
  {
    name: 'boolean checkbox',
    markup: '<input type="checkbox" BINDING>',
    initial: false,
    next: true,
  },
  {
    name: 'checkbox list',
    markup: '<input type="checkbox" value="b" BINDING>',
    initial: [],
    next: ['b'],
  },
  {
    name: 'radio',
    markup: '<input type="radio" value="b" BINDING>',
    initial: 'a',
    next: 'b',
  },
];

for (const scope of ['control', 'form']) {
  it.each(selections)(
    `${scope} .live sends $name changes immediately and only once`,
    async ({ markup, initial, next }) => {
      vi.useFakeTimers();
      const { client, fetcher, edit } = setup(markup, initial, scope);
      edit();
      expect(client.bridge.state.data).toEqual({ value: next });
      expect(fetcher).toHaveBeenCalledOnce();
      await vi.runAllTimersAsync();
      expect(fetcher).toHaveBeenCalledOnce();
      const request: unknown = fetcher.mock.calls[0]?.[1];
      if (
        typeof request !== 'object' ||
        request === null ||
        !('body' in request) ||
        typeof request.body !== 'string'
      )
        throw new Error('Missing request.');
      expect(JSON.parse(request.body)).toMatchObject({
        updates: { data: { value: next } },
      });
    },
  );

  it.each(selections)(
    `${scope} .live respects explicit debounce on $name`,
    async ({ markup, initial, next }) => {
      vi.useFakeTimers();
      const { client, fetcher, edit, control } = setup(
        markup,
        initial,
        scope,
        '.live.debounce.400ms',
      );
      edit();
      expect(client.bridge.state.data).toEqual({ value: next });
      await vi.advanceTimersByTimeAsync(200);
      // A later native change restarts the explicit debounce.
      control.dispatchEvent(new Event('change', { bubbles: true }));
      await vi.advanceTimersByTimeAsync(399);
      expect(fetcher).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1);
      expect(fetcher).toHaveBeenCalledOnce();
    },
  );

  it.each([
    ['.live.debounce', 250],
    ['.live.debounce.0ms', 0],
  ])(
    `${scope} %s explicitly controls selection debounce`,
    async (modifier, delay) => {
      vi.useFakeTimers();
      const { fetcher, edit } = setup(
        selections[0]?.markup ?? '',
        'a',
        scope,
        modifier,
      );
      edit();
      expect(fetcher).not.toHaveBeenCalled();
      if (delay > 0) {
        await vi.advanceTimersByTimeAsync(delay - 1);
        expect(fetcher).not.toHaveBeenCalled();
      }
      await vi.advanceTimersByTimeAsync(delay > 0 ? 1 : 0);
      expect(fetcher).toHaveBeenCalledOnce();
    },
  );

  it.each(['<input BINDING>', '<textarea BINDING></textarea>'])(
    `${scope} .live keeps the default typing debounce: %s`,
    async (markup) => {
      vi.useFakeTimers();
      const { fetcher, control, edit } = setup(markup, '', scope);
      edit();
      await vi.advanceTimersByTimeAsync(200);
      control.value = 'bc';
      control.dispatchEvent(new Event('input', { bubbles: true }));
      await vi.advanceTimersByTimeAsync(249);
      expect(fetcher).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1);
      expect(fetcher).toHaveBeenCalledOnce();
    },
  );
}
