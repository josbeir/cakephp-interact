import { afterEach, expect, it, vi } from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import { mountClient, destroyBindings } from '../support/mount';
import type { IslandClient } from '../../../resources/ts/client';
import type { Uploader } from '../../../resources/ts/uploads/types';

const clients: IslandClient[] = [];
function setup(markup: string, prefix = 'x-') {
  Alpine.prefix(prefix);
  const root = document.createElement('section');
  root.setAttribute(`${prefix}data`, '{}');
  Object.assign(root.dataset, {
    interactId: 'names',
    interactSnapshot: 'signed',
    interactEndpoint: '/_interact/update',
    interactState: JSON.stringify({
      title: 'original',
      other: 'absolute',
      data: {
        title: 'relative',
        enabled: false,
        tags: [],
        rows: [{ body: 'nested' }],
      },
      second: { title: 'second' },
    }),
    interactWritable: JSON.stringify({
      title: { type: 'string', nullable: false },
      other: { type: 'string', nullable: false },
      data: { type: 'array', nullable: false },
      second: { type: 'array', nullable: false },
    }),
    interactUploads: JSON.stringify({
      document: { multiple: false, maxSize: 1000, maxFiles: 1, files: [] },
      attachments: { multiple: true, maxSize: 1000, maxFiles: 2, files: [] },
    }),
  });
  root.innerHTML = markup;
  document.body.append(root);
  const fetcher = vi
    .fn()
    .mockImplementation(async () => new Response('', { status: 503 }));
  const uploader = vi
    .fn<Uploader>()
    .mockImplementation(async () => new Response('', { status: 503 }));
  const client = mountClient(root, fetcher, undefined, uploader);
  clients.push(client);
  return { root, client, fetcher, uploader };
}
function input(root: HTMLElement, selector = 'input'): HTMLInputElement {
  const element = root.querySelector(selector);
  if (!(element instanceof HTMLInputElement)) throw new Error('Missing input.');
  return element;
}
function edit(control: HTMLInputElement, value: string, event = 'input') {
  control.value = value;
  control.dispatchEvent(new Event(event, { bubbles: true }));
}
afterEach(() => {
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  document.body.innerHTML = '';
  Alpine.prefix('x-');
  vi.useRealTimers();
});

it.each(['', '.live', '.live.debounce.40ms', '.blur', '.change'])(
  'infers native names with %s and retains the requested timing',
  async (modifier) => {
    vi.useFakeTimers();
    const { root, client, fetcher } = setup(
      `<input name="title" x-interact:model${modifier}>`,
    );
    const control = input(root);
    expect(control.value).toBe('original');
    edit(control, 'draft');
    expect(client.bridge.state.title).toBe('draft');
    expect(fetcher).not.toHaveBeenCalled();
    if (modifier === '.blur')
      control.dispatchEvent(new Event('focusout', { bubbles: true }));
    else if (modifier === '.change')
      control.dispatchEvent(new Event('change', { bubbles: true }));
    else if (modifier) {
      const delay = modifier.includes('40ms') ? 40 : 250;
      await vi.advanceTimersByTimeAsync(delay - 1);
      expect(fetcher).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1);
    } else await client.bridge.call('save');
    expect(fetcher).toHaveBeenCalledOnce();
  },
);

it('shares bracket/list parsing and keeps form-relative names separate from explicit absolute paths', () => {
  const { root, client } = setup(`<form x-interact:model="data">
    <input name="title" x-interact:model>
    <input name="rows[0][body]" x-interact:model>
    <input type="hidden" name="enabled" value="0"><input name="enabled" type="checkbox" x-interact:model>
    <input type="hidden" name="tags" value=""><input name="tags[]" type="checkbox" value="b" x-interact:model>
    <input name="title" x-interact:model="other">
  </form>`);
  const controls = root.querySelectorAll('input');
  expect(controls[0]?.value).toBe('relative');
  expect(controls[1]?.value).toBe('nested');
  expect(controls[6]?.value).toBe('absolute');
  edit(input(root, 'input[name="rows[0][body]"]'), 'nested draft');
  for (const control of root.querySelectorAll<HTMLInputElement>(
    'input[type="checkbox"]',
  )) {
    control.checked = true;
    control.dispatchEvent(new Event('change', { bubbles: true }));
  }
  client.captureModels();
  expect(client.bridge.state.data).toEqual({
    title: 'relative',
    enabled: true,
    tags: ['b'],
    rows: [{ body: 'nested draft' }],
  });
  expect(client.bridge.state.other).toBe('absolute');
});

it('resolves external form-associated controls even when they precede the bound form', () => {
  const { root, client } = setup(
    '<input form="draft" name="title" x-interact:model><form id="draft" x-interact:model="data"></form>',
  );
  expect(input(root).value).toBe('relative');
  edit(input(root), 'external');
  expect(client.bridge.state.data).toMatchObject({ title: 'external' });
});

it('uses each shorthand control’s own timing inside a form with different defaults', async () => {
  vi.useFakeTimers();
  const { root, fetcher, client } = setup(
    '<form x-interact:model.blur="data"><input name="title" x-interact:model.live.debounce.40ms><input name="other" x-interact:model="other"></form>',
  );
  edit(input(root, 'input[name="other"]'), 'deferred absolute');
  await vi.advanceTimersByTimeAsync(100);
  expect(fetcher).not.toHaveBeenCalled();
  edit(input(root), 'relative live');
  await vi.advanceTimersByTimeAsync(39);
  expect(fetcher).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(1);
  expect(fetcher).toHaveBeenCalledOnce();
  expect(client.bridge.state.data).toMatchObject({ title: 'relative live' });
});

it('cancels old debounce work and restores the new draft when a native name changes', async () => {
  vi.useFakeTimers();
  const { root, fetcher, client } = setup(
    '<input name="title" x-interact:model.live.debounce.40ms>',
  );
  const control = input(root);
  edit(control, 'old draft');
  control.name = 'other';
  await Promise.resolve();
  expect(control.value).toBe('absolute');
  await vi.advanceTimersByTimeAsync(40);
  expect(fetcher).not.toHaveBeenCalled();
  edit(control, 'new draft');
  await vi.advanceTimersByTimeAsync(40);
  expect(fetcher).toHaveBeenCalledOnce();
  expect(client.bridge.state).toMatchObject({
    title: 'old draft',
    other: 'new draft',
  });
});

it('flushes form reassociation synchronously and follows changes to the form’s base path', async () => {
  const { root, client } = setup(
    '<input form="first" name="title" x-interact:model><form id="first" x-interact:model="data"></form><form id="second" x-interact:model="second"></form>',
  );
  const control = input(root);
  control.setAttribute('form', 'second');
  client.captureModels();
  expect(control.value).toBe('second');
  edit(control, 'second draft');
  root.querySelector('#second')?.setAttribute('x-interact:model', 'data');
  // The direct capture path sees the same DOM context before observers run.
  client.captureModels();
  expect(control.value).toBe('relative');
  expect(client.bridge.state.second).toEqual({ title: 'second draft' });
  await Promise.resolve();
});

it('uses absolute bracket names outside a bound form and supports custom Alpine prefixes', () => {
  const { root, client } = setup(
    '<input name="data[rows][0][body]" data-interact:model>',
    'data-',
  );
  expect(input(root).value).toBe('nested');
  edit(input(root), 'prefixed');
  expect(client.bridge.state.data).toMatchObject({
    rows: [{ body: 'prefixed' }],
  });
});

it.each([
  '',
  '_csrfToken',
  '_Token[fields]',
  '_method',
  'rows[][body]',
  'data.__proto__.x',
])('rejects an unusable shorthand name: %s', (name) => {
  expect(() => setup(`<input name="${name}" x-interact:model>`)).toThrow();
});
it.each([
  '<form name="title" x-interact:model></form>',
  '<div name="title" x-modelable="value" x-interact:model></div>',
])('requires explicit paths on forms and custom values: %s', (markup) => {
  expect(() => setup(markup)).toThrow('explicit state path');
});

it('reports invalid renamed fields and releases their pending requests', async () => {
  vi.useFakeTimers();
  const { root, client, fetcher } = setup(
    '<input name="title" x-interact:model.live>',
  );
  const control = input(root);
  edit(control, 'draft');
  control.name = '';
  await Promise.resolve();
  expect(client.bridge.error?.code).toBe('client_error');
  await vi.advanceTimersByTimeAsync(300);
  expect(fetcher).not.toHaveBeenCalled();
});

it.each(['empty', 'duplicate'])(
  'rejects an %s form binding after shorthand initialization',
  async (change) => {
    vi.useFakeTimers();
    const { root, client, fetcher } = setup(
      '<form x-interact:model="data"><input name="title" x-interact:model.live></form>',
    );
    edit(input(root), 'draft');
    const form = root.querySelector('form');
    if (!form) throw new Error('Missing form.');
    if (change === 'empty') form.setAttribute('x-interact:model', '');
    else form.setAttribute('x-interact:model.blur', 'data');
    await Promise.resolve();
    expect(client.bridge.error?.code).toBe('client_error');
    await vi.advanceTimersByTimeAsync(300);
    expect(fetcher).not.toHaveBeenCalled();
  },
);

it('releases moved and destroyed shorthand controls without crossing island ownership', async () => {
  vi.useFakeTimers();
  const { root, client, fetcher } = setup(
    '<input name="title" x-interact:model.live>',
  );
  const control = input(root);
  edit(control, 'draft');
  const child = document.createElement('div');
  child.dataset.interactId = 'child';
  root.append(child);
  child.append(control);
  await Promise.resolve();
  edit(control, 'foreign');
  await vi.advanceTimersByTimeAsync(300);
  expect(client.bridge.state.title).toBe('draft');
  expect(fetcher).not.toHaveBeenCalled();
  root.append(control);
  await Promise.resolve();
  edit(control, 'owned again');
  destroyBindings(root);
  await vi.advanceTimersByTimeAsync(300);
  expect(fetcher).not.toHaveBeenCalled();
});

it('binds named uploads through the normal transfer pipeline, including a synchronous rename', async () => {
  const { root, uploader } = setup(
    '<input name="document" type="file" x-interact:model>',
  );
  const control = input(root);
  control.name = 'attachments[]';
  control.multiple = true;
  Object.defineProperty(control, 'files', {
    value: [new File(['text'], 'notes.txt')],
    configurable: true,
  });
  control.dispatchEvent(new Event('change', { bubbles: true }));
  await vi.waitFor(() => expect(uploader).toHaveBeenCalledOnce());
  expect(uploader.mock.calls[0]?.[2]).toMatchObject({
    upload: { field: 'attachments', kind: 'stage' },
  });
});

it('retains upload modifier restrictions and writable state checks', () => {
  expect(() =>
    setup('<input type="file" name="document" x-interact:model.live>'),
  ).toThrow('no timing modifiers');
  const { root, client } = setup('<input name="locked" x-interact:model>');
  edit(input(root), 'cannot write');
  expect(client.bridge.error?.code).toBe('client_error');
  expect(client.bridge.state).not.toHaveProperty('locked');
});
