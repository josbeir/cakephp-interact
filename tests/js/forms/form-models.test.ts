import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { formName } from '../../../resources/ts/bindings/form-names';
import { modelBinding } from '../../../resources/ts/bindings/model';
import {
  mountClient,
  destroyBindings,
  refreshBindings,
} from '../support/mount';
import type { IslandClient } from '../../../resources/ts/client';
import type { State } from '../../../resources/ts/types';

const clients: IslandClient[] = [];
function setup(content: string, data: State = {}, modifier = '') {
  const root = document.createElement('div');
  Object.assign(root.dataset, {
    interactId: 'form-model',
    interactSnapshot: 'signed',
    interactEndpoint: '/_interact/update',
    interactState: JSON.stringify({
      data,
      other: 'explicit',
      editor: { data: {} },
    }),
    interactWritable: JSON.stringify({
      data: { type: 'array', nullable: false },
      other: { type: 'string', nullable: false },
      editor: { type: 'array', nullable: false },
    }),
  });
  root.innerHTML = `<form id="draft" x-interact:model${modifier}="data" x-interact:submit="$server.call('save')">${content}<button>Save</button></form>`;
  document.body.append(root);
  const fetcher = vi.fn().mockResolvedValue(new Response('', { status: 503 }));
  const client = mountClient(root, fetcher);
  clients.push(client);
  const form = root.querySelector('form');
  if (!form) throw new Error('Missing form.');
  return { root, client, form, fetcher };
}
function input(root: HTMLElement, name: string): HTMLInputElement {
  const control = [...root.querySelectorAll('input')].find(
    (control) => control.name === name,
  );
  if (!control) throw new Error(`Missing input: ${name}`);
  return control;
}
function edit(control: HTMLInputElement, value: string, event = 'input') {
  control.value = value;
  control.dispatchEvent(new Event(event, { bubbles: true }));
}
afterEach(() => {
  clients.splice(0).forEach((client) => {
    destroyBindings(client.root);
    client.dispose();
  });
  document.body.innerHTML = '';
  vi.restoreAllMocks();
  vi.useRealTimers();
});

describe('native form names', () => {
  it.each([
    ['title', 'data.title', false],
    ['comments[0][body]', 'data.comments.0.body', false],
    ['comments.0.body', 'data.comments.0.body', false],
    ['comments[0].body', 'data.comments.0.body', false],
    ['tags[]', 'data.tags', true],
    ['comments[0][tags][]', 'data.comments.0.tags', true],
  ])('resolves %s relative to array state', (name, resolved, list) => {
    expect(formName('data', name)).toEqual({ path: resolved, list });
    expect(formName('editor.data', name)?.path).toBe(`editor.${resolved}`);
  });
  it.each([
    'comments[][body]',
    'tags[][]',
    'title[',
    'title]',
    'a..b',
    '[title]',
    'a[0]b',
    'a.__proto__.b',
    'constructor',
    'a[prototype]',
    'a[b.c]',
    'a b',
  ])('rejects unsafe or ambiguous names: %s', (name) => {
    expect(() => formName('data', name)).toThrow();
  });
  it.each(['', '_csrfToken', '_method', '_Token[fields]', '_Token.unlocked'])(
    'excludes native bookkeeping: %s',
    (name) => {
      expect(formName('data', name)).toBeNull();
    },
  );
});

describe('form model discovery and values', () => {
  it('discovers a large form once during explicit registration and reuses it while typing', async () => {
    const discover = vi.spyOn(HTMLFormElement.prototype, 'elements', 'get');
    const { root, client } = setup(
      Array.from(
        { length: 100 },
        (_, index) =>
          `<input name="field${index}" x-interact:model="data.field${index}">`,
      ).join('') + '<input name="inferred">',
    );
    expect(discover).toHaveBeenCalledTimes(1);
    discover.mockClear();
    for (let index = 0; index < 10; index++)
      edit(input(root, 'field0'), `draft${index}`);
    edit(input(root, 'inferred'), 'inferred draft');
    client.captureModels();
    expect(client.bridge.state.data).toMatchObject({
      field0: 'draft9',
      inferred: 'inferred draft',
    });
    expect(discover).not.toHaveBeenCalled();
    root.setAttribute('class', 'presentation');
    await Promise.resolve();
    edit(input(root, 'field0'), 'after presentation');
    expect(discover).not.toHaveBeenCalled();
  });

  it('invalidates discovery before synchronous capture and uses one scan for both refresh passes', () => {
    const { root, client, form } = setup('<input name="title">', {
      renamed: 'initial',
    });
    const discover = vi.spyOn(form, 'elements', 'get');
    const title = input(root, 'title');
    title.name = 'renamed';
    const added = document.createElement('input');
    added.name = 'added';
    form.append(added);
    client.captureModels();
    expect(discover).toHaveBeenCalledTimes(1);
    expect(title.value).toBe('initial');
    added.value = 'before input';
    client.captureModels();
    expect(client.bridge.state.data).toMatchObject({ added: 'before input' });
    expect(discover).toHaveBeenCalledTimes(1);
  });

  it('uses declared state, empty semantics, and explicit absolute overrides', async () => {
    const { root, client } = setup(
      `
      <input name="title" value="entity value">
      <textarea name="body">entity body</textarea>
      <input name="comments[0][body]">
      <input name="override" x-interact:model.blur="other">
      <input name="missing" value="must not import">
      <input name="nil"><input name="no"><input name="zero"><input name="empty">
      <input name="enabled" type="checkbox">
      <input name="quantity" type="number">
    `,
      {
        title: 'draft',
        body: 'body',
        comments: [{ body: 'comment' }],
        nil: null,
        no: false,
        zero: 0,
        empty: '',
        enabled: false,
      },
    );
    expect(input(root, 'title').value).toBe('draft');
    expect(root.querySelector('textarea')?.value).toBe('body');
    expect(input(root, 'comments[0][body]').value).toBe('comment');
    expect(input(root, 'override').value).toBe('explicit');
    expect(
      ['missing', 'nil', 'no', 'zero', 'empty'].map(
        (name) => input(root, name).value,
      ),
    ).toEqual(['', '', 'false', '0', '']);
    edit(input(root, 'title'), 'edited');
    edit(input(root, 'override'), 'absolute');
    edit(input(root, 'quantity'), '42');
    input(root, 'enabled').checked = true;
    input(root, 'enabled').dispatchEvent(
      new Event('change', { bubbles: true }),
    );
    expect(client.bridge.state.data).toMatchObject({
      title: 'edited',
      enabled: true,
      quantity: '42',
    });
    expect(client.bridge.state.other).toBe('absolute');
    expect(client.bridge.state.data).not.toHaveProperty('override');
  });

  it('keeps explicit paths independent of native name syntax and follows dynamic overrides', async () => {
    const { root, client } = setup(
      '<input name="ambiguous[][name]" x-interact:model="other"><input name="title">',
    );
    edit(input(root, 'ambiguous[][name]'), 'absolute');
    expect(client.bridge.state.other).toBe('absolute');
    const title = input(root, 'title');
    title.setAttribute('x-interact:model', 'other');
    const release = client.bindModel(title, modelBinding('other', []));
    edit(title, 'override');
    expect(client.bridge.state.other).toBe('override');
    expect(client.bridge.state.data).toEqual({});
    release();
    title.removeAttribute('x-interact:model');
    await Promise.resolve();
    await Promise.resolve();
    edit(title, 'inferred again');
    expect(client.bridge.state.data).toEqual({ title: 'inferred again' });
  });

  it('handles hidden fallbacks, list leaves, empty checked values, radios, selects, and nested booleans', async () => {
    const { root, client } = setup(
      `
      <input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1">
      <input type="hidden" name="tags" value=""><input type="checkbox" name="tags[]" value=""><input type="checkbox" name="tags[]" value="one">
      <input type="checkbox" name="existing" value="two">
      <input type="hidden" name="choice" value=""><input type="radio" name="choice" value="a"><input type="radio" name="choice" value="b">
      <input type="hidden" name="options" value=""><select name="options[]" multiple><option value="">Empty</option><option value="x">X</option></select>
      <select name="country"><option value="">Choose</option><option value="BE">Belgium</option></select>
      <input type="hidden" name="standalone" value="entity">
      <input type="hidden" name="_csrfToken" value="csrf"><input type="hidden" name="_Token[fields]" value="token"><input type="hidden" name="_method" value="POST">
      <input type="checkbox" name="nested[enabled]">
    `,
      {
        enabled: false,
        existing: ['two'],
        choice: 'b',
        nested: { enabled: true },
        standalone: 'draft',
      },
    );
    const checks = [
      ...root.querySelectorAll<HTMLInputElement>('input[type=checkbox]'),
    ];
    checks.forEach((control) => {
      control.checked = true;
    });
    const select = root.querySelector<HTMLSelectElement>('select[multiple]');
    if (!select) throw new Error('Missing select.');
    for (const option of select.options) option.selected = true;
    await client.bridge.call('save');
    expect(client.bridge.state.data).toEqual({
      enabled: true,
      tags: ['', 'one'],
      existing: ['two'],
      choice: 'b',
      options: ['', 'x'],
      country: '',
      standalone: 'draft',
      nested: { enabled: true },
    });
    expect(client.bridge.state.data).not.toHaveProperty('_Token');
  });

  it('excludes disabled fieldsets, honoring the first legend exception', async () => {
    const { root, client } = setup(
      '<fieldset disabled><legend><input name="legend"></legend><input name="disabled"></fieldset><input disabled name="direct">',
    );
    for (const name of ['legend', 'disabled', 'direct'])
      edit(input(root, name), 'edited');
    await client.bridge.sync();
    expect(client.bridge.state.data).toEqual({ legend: 'edited' });
  });

  it('skips unnamed, unsupported, Alpine, ignored, modelable, and child-island controls', async () => {
    const { root, client } = setup(`
      <input value="unbound"><input name="file" type="file"><input name="button" type="button"><input name="reset" type="reset"><input name="submit" type="submit">
      <input name="alpine" x-data="{value: ''}" x-model="value">
      <div x-interact:ignore><input name="ignored"></div>
      <div x-data="{value: ''}" x-modelable="value" x-interact:model="other"><input name="widget" x-model="value"></div>
      <div data-interact-id="child"><input name="child"></div>
    `);
    await client.bridge.sync();
    expect(client.bridge.state.data).toEqual({});
    expect(root.querySelector('input[type=file]')).toHaveProperty('value', '');
  });

  it('tracks additions, removal, rename, external association and reassignment before capture', async () => {
    const { root, client, form } = setup('<input name="title">', {
      title: 'initial',
      renamed: 'renamed',
      external: 'external',
    });
    const external = document.createElement('input');
    external.name = 'external';
    external.setAttribute('form', 'draft');
    root.append(external);
    await Promise.resolve();
    expect(external.value).toBe('external');
    external.value = 'outside';
    const title = input(root, 'title');
    title.name = 'renamed';
    await Promise.resolve();
    expect(title.value).toBe('renamed');
    edit(title, 'new');
    const added = document.createElement('input');
    added.name = 'added';
    form.append(added);
    await Promise.resolve();
    edit(added, 'added draft');
    const unbound = document.createElement('form');
    unbound.id = 'unbound';
    root.append(unbound);
    external.setAttribute('form', 'unbound');
    title.remove();
    await client.bridge.call('save');
    expect(client.bridge.state.data).toEqual({
      title: 'initial',
      renamed: 'new',
      external: 'external',
      added: 'added draft',
    });
  });

  it('transfers controls between bound forms in either registration order', async () => {
    const { root, form, client } = setup('<input name="title">', {
      title: 'first',
    });
    const other = document.createElement('form');
    other.id = 'second';
    other.setAttribute('x-interact:model', 'editor.data');
    other.innerHTML = '<input name="title">';
    root.append(other);
    refreshBindings(root);
    const moved = input(other, 'title');
    edit(moved, 'second draft');
    form.append(moved);
    await Promise.resolve();
    expect(moved.value).toBe('first');
    edit(moved, 'first draft');
    other.append(moved);
    await Promise.resolve();
    expect(moved.value).toBe('second draft');
    expect(client.bridge.error).toBeNull();
    expect(client.bridge.state.data).toEqual({ title: 'first draft' });
    expect(client.bridge.state.editor).toEqual({
      data: { title: 'second draft' },
    });
  });

  it('cancels live timers when names, form association, ownership, or directive lifetimes end', async () => {
    vi.useFakeTimers();
    const { root, client, form, fetcher } = setup(
      '<input name="title">',
      {},
      '.live.debounce.50ms',
    );
    const control = input(root, 'title');
    edit(control, 'draft');
    control.name = 'renamed';
    await Promise.resolve();
    await vi.advanceTimersByTimeAsync(60);
    expect(fetcher).not.toHaveBeenCalled();
    edit(control, 'moved');
    const child = document.createElement('div');
    child.dataset.interactId = 'child';
    root.append(child);
    child.append(control);
    await Promise.resolve();
    await vi.advanceTimersByTimeAsync(60);
    expect(fetcher).not.toHaveBeenCalled();
    form.append(control);
    await Promise.resolve();
    edit(control, 'dispose');
    destroyBindings(root);
    client.dispose();
    await vi.runAllTimersAsync();
    expect(fetcher).not.toHaveBeenCalled();
  });

  it.each([
    ['checkbox', 'remove'],
    ['checkbox', 'rename'],
    ['checkbox', 'reassign'],
    ['radio', 'remove'],
    ['radio', 'rename'],
    ['radio', 'reassign'],
  ])(
    'keeps another %s member live when a peer is %s',
    async (type, mutation) => {
      vi.useFakeTimers();
      const name = type === 'checkbox' ? 'choice[]' : 'choice';
      const { root, fetcher } = setup(
        `<input type="${type}" name="${name}" value="a"><input type="${type}" name="${name}" value="b">`,
        { choice: type === 'checkbox' ? [] : '' },
        '.live.debounce.50ms',
      );
      const [peer, source] = root.querySelectorAll('input');
      if (!peer || !source) throw new Error('Missing group members.');
      source.checked = true;
      source.dispatchEvent(new Event('input', { bubbles: true }));
      source.dispatchEvent(new Event('change', { bubbles: true }));
      if (mutation === 'remove') peer.remove();
      if (mutation === 'rename') peer.name = 'renamed';
      if (mutation === 'reassign') peer.setAttribute('form', 'unbound');
      await Promise.resolve();
      await vi.advanceTimersByTimeAsync(60);
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
        updates: { data: { choice: type === 'checkbox' ? ['b'] : 'b' } },
      });
    },
  );

  it('cancels a removed group member’s own pending live edit', async () => {
    vi.useFakeTimers();
    const { root, fetcher } = setup(
      '<input type="checkbox" name="tags[]" value="a"><input type="checkbox" name="tags[]" value="b">',
      { tags: [] },
      '.live.debounce.50ms',
    );
    const source = input(root, 'tags[]');
    source.checked = true;
    source.dispatchEvent(new Event('input', { bubbles: true }));
    source.dispatchEvent(new Event('change', { bubbles: true }));
    source.remove();
    await Promise.resolve();
    await vi.advanceTimersByTimeAsync(60);
    expect(fetcher).not.toHaveBeenCalled();
  });

  it.each(['.blur', '.change', '.live.debounce.20ms'])(
    'inherits %s timing while explicit controls retain their own timing',
    async (modifier) => {
      vi.useFakeTimers();
      const { root, fetcher } = setup(
        '<input name="title"><input name="override" x-interact:model="other">',
        {},
        modifier,
      );
      edit(input(root, 'override'), 'deferred');
      await vi.advanceTimersByTimeAsync(30);
      expect(fetcher).not.toHaveBeenCalled();
      edit(input(root, 'title'), 'draft');
      if (modifier === '.blur')
        input(root, 'title').dispatchEvent(
          new Event('focusout', { bubbles: true }),
        );
      if (modifier === '.change')
        input(root, 'title').dispatchEvent(
          new Event('change', { bubbles: true }),
        );
      await vi.advanceTimersByTimeAsync(30);
      expect(fetcher).toHaveBeenCalledOnce();
    },
  );

  it('captures submit-before-blur with the unchanged JSON action protocol', async () => {
    const { root, client, form, fetcher } = setup(
      '<input name="title">',
      {},
      '.blur',
    );
    input(root, 'title').value = 'unblurred';
    form.requestSubmit();
    form.requestSubmit();
    await vi.waitFor(() => expect(client.bridge.pending()).toBe(false));
    expect(fetcher).toHaveBeenCalledOnce();
    const options: unknown = fetcher.mock.calls[0]?.[1];
    if (
      typeof options !== 'object' ||
      options === null ||
      !('body' in options) ||
      typeof options.body !== 'string'
    )
      throw new Error('Missing request.');
    expect(JSON.parse(options.body)).toMatchObject({
      action: 'save',
      updates: { data: { title: 'unblurred' } },
    });
  });

  it('accepts nested array paths and rejects scalar or locked form roots and duplicate form declarations', () => {
    const { form, root } = setup('<input name="title">');
    form.setAttribute('x-interact:model', 'editor.data');
    refreshBindings(root);
    edit(input(root, 'title'), 'nested');
    const client = clients.at(-1);
    expect(client?.bridge.state.editor).toEqual({ data: { title: 'nested' } });
    for (const path of ['other', 'missing']) {
      form.setAttribute('x-interact:model', path);
      expect(() => refreshBindings(root)).toThrow('writable array');
    }
    form.setAttribute('x-interact:model', 'data');
    form.setAttribute('x-interact:model.blur', 'data');
    expect(() => refreshBindings(root)).toThrow('one x-interact:model');
  });

  it('reports invalid names once without observing its own reactive error feedback', async () => {
    const { root, client } = setup(
      `<input name="title"><p x-text="$server.error ? $server.error.message : ''"></p>`,
    );
    const failure = vi.fn();
    root.addEventListener('interact:error', failure);
    input(root, 'title').name = 'unsafe[][name]';
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    expect(client.bridge.error?.code).toBe('client_error');
    expect(root.querySelector('p')?.textContent).not.toBe('');
    expect(failure).toHaveBeenCalledOnce();
  });

  it('rejects an edit with an invalid new name before mutation discovery runs', () => {
    const { root, client, fetcher } = setup('<input name="title">', {
      title: 'accepted draft',
    });
    const title = input(root, 'title');
    title.name = 'comments[][body]';
    edit(title, 'must not capture');
    expect(client.bridge.error?.code).toBe('client_error');
    expect(client.bridge.state.data).toEqual({ title: 'accepted draft' });
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('reports unsafe dynamic names and cleans up partially registered invalid forms', async () => {
    const { root, client, form } = setup('<input name="title">');
    input(root, 'title').name = 'comments[][body]';
    await Promise.resolve();
    expect(client.bridge.error?.code).toBe('client_error');
    input(root, 'comments[][body]').name = 'title';
    form.setAttribute('x-interact:model', 'data');
    const bad = document.createElement('input');
    bad.name = '__proto__';
    form.append(bad);
    expect(() => refreshBindings(root)).toThrow('Unsafe');
    bad.remove();
  });
});
