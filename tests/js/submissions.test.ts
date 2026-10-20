import { afterEach, describe, expect, it } from 'vite-plus/test';
import { FormSubmissions } from '../../resources/ts/bindings/form-submissions';
import type { RequestResult } from '../../resources/ts/types';

const guards: FormSubmissions[] = [];
function setup() {
  const root = document.createElement('div');
  root.dataset.interactId = 'parent';
  root.innerHTML =
    '<form id="save-form" x-interact:submit="$server.call(\'save\')"><input value="draft"><button>Save</button><button disabled>Unavailable</button><button type="button">Other action</button><input type="image" alt="Submit"></form><button form="save-form">External submit</button><div data-interact-id="child"><button form="save-form">Child</button></div>';
  document.body.append(root);
  const form = root.querySelector('form');
  const button = root.querySelector('button');
  const input = root.querySelector('input');
  if (!form || !button || !input) throw new Error('Missing form fixture.');
  const guard = new FormSubmissions(root, () => {});
  guards.push(guard);
  let complete: (result: RequestResult) => void = () => {};
  const pending = new Promise<RequestResult>((resolve) => {
    complete = resolve;
  });
  return { root, form, button, input, guard, pending, complete };
}
afterEach(() => {
  guards.splice(0).forEach((guard) => guard.dispose());
  document.body.innerHTML = '';
});

describe('native submission protection', () => {
  it('captures once before disabling submitters, keeping fields and child islands independent', async () => {
    const { root, form, button, input, guard, pending, complete } = setup();
    let calls = 0;
    const invoke = () => {
      calls++;
      expect(button.disabled).toBe(false);
      return pending;
    };
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      invoke,
    );
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      invoke,
    );
    expect(calls).toBe(1);
    expect(button.disabled).toBe(true);
    expect(form.getAttribute('aria-busy')).toBe('true');
    expect(root.querySelector('button[form]')).toHaveProperty('disabled', true);
    expect(root.querySelector('input[type=image]')).toHaveProperty(
      'disabled',
      true,
    );
    expect(root.querySelector('button[type=button]')).toHaveProperty(
      'disabled',
      false,
    );
    expect(
      root.querySelector('[data-interact-id="child"] button'),
    ).toHaveProperty('disabled', false);
    expect(input.disabled).toBe(false);
    input.value = 'newer';
    complete({ ok: true });
    await pending;
    expect(button.disabled).toBe(false);
    expect(root.querySelectorAll('button')[1]).toHaveProperty('disabled', true);
    expect(form.hasAttribute('aria-busy')).toBe(false);
    expect(input.value).toBe('newer');
  });
  it('preserves newly rendered disabled state while following a keyed replacement', async () => {
    const { root, form, guard, pending, complete } = setup();
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      () => pending,
    );
    guard.beforeMorph();
    root.innerHTML =
      '<form id="save-form" x-interact:submit="$server.call(\'save\')" aria-busy="false"><button disabled>Still unavailable</button></form>';
    guard.refresh();
    const replacement = root.querySelector('form');
    expect(replacement?.getAttribute('aria-busy')).toBe('true');
    complete({ ok: true });
    await pending;
    expect(root.querySelector('button')).toHaveProperty('disabled', true);
    expect(replacement?.getAttribute('aria-busy')).toBe('false');
  });
  it('retains application disabled changes and protects newly inserted submitters', async () => {
    const { root, form, button, guard, pending, complete } = setup();
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      () => pending,
    );
    button.disabled = false;
    await Promise.resolve();
    expect(button.disabled).toBe(true);
    button.disabled = true;
    const added = document.createElement('button');
    form.append(added);
    await Promise.resolve();
    expect(added.disabled).toBe(true);
    complete({ ok: true });
    await pending;
    expect(button.disabled).toBe(true);
    expect(added.disabled).toBe(false);
    expect(root.querySelector('form')).toBe(form);
  });
  it('does not transfer locks to unkeyed replacements and releases disposal without late mutations', async () => {
    const { root, form, button, guard, pending, complete } = setup();
    form.removeAttribute('id');
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      () => pending,
    );
    guard.beforeMorph();
    root.innerHTML =
      '<form x-interact:submit="$server.call(\'save\')"><button>New form</button></form>';
    guard.refresh();
    expect(root.querySelector('button')).toHaveProperty('disabled', false);
    expect(button.disabled).toBe(false);
    guard.dispose();
    complete({ ok: true });
    await pending;
    expect(root.querySelector('button')).toHaveProperty('disabled', false);
  });
  it('keeps separate forms independent and disposes active locks without late cleanup changing the DOM', async () => {
    const { root, form, button, guard, pending, complete } = setup();
    const other = document.createElement('form');
    other.setAttribute('x-interact:submit', '$server.call("other")');
    other.innerHTML = '<button>Other save</button>';
    root.append(other);
    const otherButton = other.querySelector('button');
    if (!otherButton) throw new Error('Missing second form.');
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      () => pending,
    );
    expect(otherButton.disabled).toBe(false);
    let done: (result: RequestResult) => void = () => {};
    const otherPending = new Promise<RequestResult>((resolve) => {
      done = resolve;
    });
    guard.submit(
      other,
      'x-interact:submit',
      other.getAttribute('x-interact:submit') ?? '',
      () => otherPending,
    );
    expect(otherButton.disabled).toBe(true);
    done({ ok: true });
    await otherPending;
    expect(otherButton.disabled).toBe(false);
    expect(button.disabled).toBe(true);
    guard.dispose();
    expect(button.disabled).toBe(false);
    expect(form.hasAttribute('aria-busy')).toBe(false);
    button.disabled = true;
    complete({ ok: true });
    await pending;
    expect(button.disabled).toBe(true);
  });
  it('tracks stable form IDs and aria-busy even without submit buttons', async () => {
    const { form, root, guard, pending, complete } = setup();
    form.id = 'editor';
    form.innerHTML = '<input>';
    form.setAttribute('aria-busy', 'false');
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      () => pending,
    );
    guard.refresh();
    guard.beforeMorph();
    root.innerHTML =
      '<form id="editor" x-interact:submit="$server.call(\'save\')"><input></form>';
    guard.refresh();
    complete({
      ok: false,
      error: {
        code: 'network_error',
        message: 'Failed',
        status: null,
        recovery: 'retry',
      },
    });
    await pending;
    expect(root.querySelector('form')?.hasAttribute('aria-busy')).toBe(false);
  });
  it('does not transfer a removed form lock to unrelated forms or child islands', async () => {
    const { root, form, guard, pending, complete } = setup();
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      () => pending,
    );
    guard.beforeMorph();
    root.innerHTML =
      '<form x-interact:submit="$server.call(\'save\')"><button>Unkeyed</button></form><form id="other" x-interact:submit="$server.call(\'save\')"><button>Other key</button></form><form id="save-form" x-interact:submit="$server.call(\'delete\')"><button>Other action</button></form><div data-interact-id="child"><form id="save-form" x-interact:submit="$server.call(\'save\')"><button>Child</button></form></div>';
    guard.refresh();
    for (const candidate of root.querySelectorAll('form')) {
      expect(candidate.hasAttribute('aria-busy')).toBe(false);
      expect(candidate.querySelector('button')).toHaveProperty(
        'disabled',
        false,
      );
    }
    complete({ ok: true });
    await pending;
    expect(root.querySelectorAll('button:disabled')).toHaveLength(0);
  });
  it('keeps application field availability independent of the submit lock', async () => {
    const { form, button, input, guard, pending, complete } = setup();
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      () => pending,
    );
    input.disabled = true;
    await Promise.resolve();
    expect(input.disabled).toBe(true);
    expect(button.disabled).toBe(true);
    input.disabled = false;
    await Promise.resolve();
    expect(input.disabled).toBe(false);
    expect(button.disabled).toBe(true);
    complete({ ok: true });
    await pending;
    expect(input.disabled).toBe(false);
    expect(button.disabled).toBe(false);
  });
  it('does not restore form or button state after ownership moves to a child island', async () => {
    const { root, form, button, guard, pending, complete } = setup();
    guard.submit(
      form,
      'x-interact:submit',
      form.getAttribute('x-interact:submit') ?? '',
      () => pending,
    );
    const child = root.querySelector('[data-interact-id="child"]');
    if (!child) throw new Error('Missing child island.');
    child.append(form);
    form.setAttribute('aria-busy', 'child-owned');
    await Promise.resolve();
    complete({ ok: true });
    await pending;
    expect(button.disabled).toBe(true);
    expect(form.getAttribute('aria-busy')).toBe('child-owned');
    expect(root.querySelector('button[form]')).toHaveProperty(
      'disabled',
      false,
    );
  });
});
