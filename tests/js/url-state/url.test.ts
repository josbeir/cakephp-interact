import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import {
  readField,
  readQuery,
  urlFields,
  writeQuery,
  sameFields,
} from '../../../resources/ts/url/codec';
import { pageUrl, UrlCoordinator } from '../../../resources/ts/url/coordinator';
import type {
  UrlFields,
  UrlRestoration,
  UrlValue,
} from '../../../resources/ts/url/types';
import type { State } from '../../../resources/ts/types';

const fields: UrlFields = {
  search: { name: 'q', type: 'string', default: 'start', history: false },
  page: { name: 'page', type: 'int', default: 1, history: true },
  tags: { name: 'tags', type: 'array', default: ['default'], history: false },
};
const baseline: State = { search: 'start', page: 1, tags: ['default'] };
afterEach(() => {
  vi.restoreAllMocks();
  history.replaceState(null, '', '/');
});

const values: [string, string, UrlValue, UrlValue][] = [
  ['string', 'q=', 'start', ''],
  ['string', '??q=bad', 'start', 'start'],
  ['string', 'q=caf%C3%A9+%26+cake', '', 'café & cake'],
  ['string', 'q=a&q=b', '', 'b'],
  ['string', 'q[x]=bad', 'start', 'start'],
  ['int', 'q=0', 1, 0],
  ['int', 'q=-2', 1, -2],
  ['int', 'q=2%0A', 1, 1],
  ['float', 'q=3.5%0A', 1.5, 1.5],
  ['array', 'q[0]%0A=x', ['default'], ['default']],
  ['int', 'q=01', 1, 1],
  ['int', 'q=abc', 1, 1],
  ['int', 'q=9007199254740992', 1, 1],
  ['float', 'q=2.25e2', 1.5, 225],
  ['float', 'q=1e999', 1.5, 1.5],
  ['float', 'q=9007199254740993', 1.5, 1.5],
  ['float', 'q=NaN', 1.5, 1.5],
  ['bool', 'q=1', false, true],
  ['bool', 'q=0', true, false],
  ['bool', 'q=true', false, false],
  ['array', 'q=', ['default'], []],
  ['array', 'q[]=', [], ['']],
  ['array', 'q[]=a&q[]=b', [], ['a', 'b']],
  ['array', 'q[0]=a&q[1]=b', [], ['a', 'b']],
  ['array', 'q[1]=b', ['default'], ['default']],
  ['array', 'q[x][y]=bad', [], { x: { y: 'bad' } }],
  ['array', 'q=&q[]=a', ['default'], ['default']],
  ['array', 'q=a', ['default'], ['default']],
  ['array', 'q[0]=a&q[0]=b', [], ['b']],
  ['array', '', ['default'], ['default']],
  ['string', '', 'start', 'start'],
  ['string', 'q[]=', 'start', 'start'],
];

describe('query codec', () => {
  it.each(values)(
    'decodes %s from %s with a lossless default',
    (type, query, fallback, expected) => {
      const metadata: unknown = {
        value: { name: 'q', type, default: fallback, history: false },
      };
      if (!urlFields(metadata)) throw new Error('Invalid test declaration.');
      const declared = metadata.value;
      if (!declared) throw new Error('Missing test field.');
      expect(readField(new URLSearchParams(query), declared)).toEqual(expected);
    },
  );

  it.each([
    ['?q=%FF&page=3', { ...baseline, page: 3 }],
    ['?q=%C3&tags[]=valid&tags[]=%FF', baseline],
    ['?q=cake&other=%FF', { ...baseline, search: 'cake' }],
    ['?q=50%25+off+%zz+%2', { ...baseline, search: '50% off %zz %2' }],
    ['?q=%EF%BF%BD', { ...baseline, search: '\uFFFD' }],
    ['??q=%FF&q=cake', { ...baseline, search: 'cake' }],
  ])(
    'preserves valid fields and defaults malformed UTF-8 from %s',
    (search, expected) => {
      expect(readQuery(search, fields)).toEqual(expected);
    },
  );

  it('compares declaration contracts independently of object key order', () => {
    expect(
      sameFields(fields, Object.fromEntries(Object.entries(fields).reverse())),
    ).toBe(true);
    expect(sameFields(fields, {})).toBe(false);
    expect(
      sameFields(fields, {
        ...fields,
        page: { name: 'page', type: 'int', default: 2, history: true },
      }),
    ).toBe(false);
  });

  it('writes scalars and lists without losing unrelated parameters or fragments', () => {
    const url = new URL(
      'https://example.test/search?external=one&external=two&q[bad]=x#results',
    );
    writeQuery(url, fields, { search: '', page: 3, tags: ['one', ''] });
    expect(url.searchParams.getAll('external')).toEqual(['one', 'two']);
    expect(url.hash).toBe('#results');
    expect(url.pathname).toBe('/search');
    expect(readQuery(url.search, fields)).toEqual({
      search: '',
      page: 3,
      tags: ['one', ''],
    });
    writeQuery(url, fields, baseline);
    expect(url.search).toBe('?external=one&external=two');
    writeQuery(url, fields, { search: 'start', page: 1, tags: [] });
    expect(url.searchParams.get('tags')).toBe('');
  });

  it.each([
    ['bool', true, '1'],
    ['bool', false, '0'],
    ['float', 2.5, '2.5'],
  ])('encodes %s', (type, value, encoded) => {
    const metadata = {
      value: {
        name: 'value',
        type,
        default: type === 'bool' ? !value : 1,
        history: false,
      },
    };
    if (!urlFields(metadata)) throw new Error('Invalid test declaration.');
    const url = new URL('https://example.test');
    if (typeof value !== 'number' && typeof value !== 'boolean')
      throw new Error('Invalid test value.');
    writeQuery(url, metadata, { value });
    expect(url.searchParams.get('value')).toBe(encoded);
  });

  it.each([
    null,
    [],
    { value: null },
    {
      value: { name: '__proto__', type: 'string', default: '', history: false },
    },
    { value: { name: 'a.b', type: 'string', default: '', history: false } },
    { value: { name: 'q', type: 'object', default: {}, history: false } },
    { value: { name: 'q', type: 'array', default: [1], history: false } },
    { value: { name: 'q', type: 'string', default: null, history: false } },
    { value: { name: 'q', type: 'string', default: '', history: 'push' } },
  ])('rejects invalid metadata %j', (value) => {
    expect(urlFields(value)).toBe(false);
  });

  it('rejects duplicate ownership in metadata and invalid accepted values', () => {
    expect(urlFields({ one: fields.search, two: fields.search })).toBe(false);
    expect(() =>
      writeQuery(new URL('https://example.test'), fields, {
        search: {},
        page: 1,
        tags: [],
      }),
    ).toThrow('Invalid accepted URL state');
  });
});

describe('page URL ownership and history', () => {
  it('keeps URL lists detached from initial and later drafts when selecting owned state', async () => {
    const coordinator = new UrlCoordinator(window);
    const tags = ['initial'];
    const registration = coordinator.register(
      { tags: { name: 'tags', type: 'array', default: [], history: true } },
      { tags, draft: { text: 'unrelated' } },
      () => {},
    );
    tags.push('edited before initialization');
    await Promise.resolve();
    expect(location.search).toBe('?tags%5B0%5D=initial');
    const push = vi.spyOn(history, 'pushState');
    const accepted = ['accepted'];
    registration.accept({ tags: accepted, draft: [] }, 0, false);
    accepted.push('newer');
    registration.accept({ tags: ['accepted', 'newer'] }, 0, false);
    expect(push).toHaveBeenCalledTimes(2);
    expect(location.search).toBe('?tags%5B0%5D=accepted&tags%5B1%5D=newer');
    expect(() => registration.accept({}, 0, false)).toThrow(
      'Invalid accepted URL state',
    );
    registration.dispose();
  });

  it('canonicalizes once, preserves history state, and pushes only selected changes', async () => {
    history.replaceState(
      { application: 'keep' },
      '',
      '/search?external=yes&q=start#results',
    );
    const coordinator = new UrlCoordinator(window);
    const restore = vi.fn<(target: UrlRestoration) => void>();
    const registration = coordinator.register(fields, baseline, restore);
    await Promise.resolve();
    expect(location.search).toBe('?external=yes');
    const push = vi.spyOn(history, 'pushState');
    registration.accept({ ...baseline, search: 'cake' }, 0, false);
    expect(push).not.toHaveBeenCalled();
    registration.accept({ ...baseline, search: 'cake', page: 2 }, 0, false);
    expect(push).toHaveBeenCalledTimes(1);
    expect(history.state).toEqual({ application: 'keep' });
    expect(location.hash).toBe('#results');
    registration.accept({ ...baseline, search: 'cake', page: 2 }, 0, false);
    expect(push).toHaveBeenCalledTimes(1);
    history.replaceState(history.state, '', '/search?q=earlier&page=3');
    window.dispatchEvent(new PopStateEvent('popstate'));
    expect(restore).toHaveBeenCalledWith({
      values: { ...baseline, search: 'earlier', page: 3 },
      epoch: 1,
    });
    expect(registration.epoch()).toBe(1);
    registration.accept({ ...baseline, search: 'late', page: 9 }, 0, false);
    expect(location.search).toBe('?q=earlier&page=3');
    registration.accept({ ...baseline, search: 'earlier', page: 3 }, 1, true);
    expect(push).toHaveBeenCalledTimes(1);
    registration.dispose();
    window.dispatchEvent(new PopStateEvent('popstate'));
    expect(restore).toHaveBeenCalledTimes(1);
  });

  it('merges independently owned responses and detects collisions', async () => {
    const coordinator = new UrlCoordinator(window);
    const first = coordinator.register(fields, baseline, () => {});
    expect(() => coordinator.register(fields, baseline, () => {})).toThrow(
      'urlPrefix',
    );
    const secondFields: UrlFields = {
      search: { name: 'other_q', type: 'string', default: '', history: false },
    };
    const second = coordinator.register(secondFields, { search: '' }, () => {});
    await Promise.resolve();
    first.accept({ ...baseline, search: 'cake' }, 0, false);
    second.accept({ search: 'other' }, 0, false);
    expect(location.search).toBe('?q=cake&other_q=other');
    first.dispose();
    const replacement = coordinator.register(fields, baseline, () => {});
    replacement.dispose();
    second.dispose();
    await Promise.resolve();
  });

  it('releases page coordinators and skips obsolete initialization after navigation', async () => {
    const coordinator = pageUrl(window);
    expect(pageUrl(window)).toBe(coordinator);
    const registration = coordinator.register(fields, baseline, () => {});
    history.replaceState(null, '', '/search?q=navigated');
    window.dispatchEvent(new PopStateEvent('popstate'));
    await Promise.resolve();
    expect(location.search).toBe('?q=navigated');
    registration.dispose();
    const next = pageUrl(window);
    expect(next).not.toBe(coordinator);
    next.register(fields, baseline, () => {}).dispose();
  });
  it('reports history API and restoration callback errors to the owning island', async () => {
    const failure = vi.fn<(cause: unknown) => void>();
    const coordinator = new UrlCoordinator(window);
    const registration = coordinator.register(
      fields,
      baseline,
      () => {
        throw new Error('restore failed');
      },
      failure,
    );
    await Promise.resolve();
    window.dispatchEvent(new PopStateEvent('popstate'));
    expect(failure).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'restore failed' }),
    );
    registration.dispose();
    registration.dispose();
    history.replaceState(null, '', '/?q=non-default');
    const broken = vi.spyOn(history, 'replaceState').mockImplementation(() => {
      throw new Error('history failed');
    });
    const next = coordinator.register(fields, baseline, () => {}, failure);
    await Promise.resolve();
    expect(failure).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'history failed' }),
    );
    broken.mockRestore();
    next.dispose();
  });
  it('releases a disconnected root before a replacement claims the same parameters', async () => {
    const coordinator = new UrlCoordinator(window);
    const original = document.createElement('div');
    document.body.append(original);
    const first = coordinator.register(
      fields,
      baseline,
      () => {},
      () => {},
      original,
    );
    original.remove();
    const replacement = document.createElement('div');
    document.body.append(replacement);
    const second = coordinator.register(
      fields,
      baseline,
      () => {},
      () => {},
      replacement,
    );
    first.dispose();
    await Promise.resolve();
    second.accept({ ...baseline, search: 'replacement' }, 0, false);
    expect(location.search).toBe('?q=replacement');
    second.dispose();
    replacement.remove();
  });
});
