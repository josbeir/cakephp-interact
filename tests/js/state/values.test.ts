import { describe, expect, it } from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import { refreshControl } from '../../../resources/ts/bindings/control-values';
import {
  clone,
  equal,
  json,
  record,
  state,
} from '../../../resources/ts/state/values';
import { path, read, write } from '../../../resources/ts/state/paths';
import { response, writable } from '../../../resources/ts/transport/protocol';
import type { Json, State } from '../../../resources/ts/types';

describe('transport values', () => {
  it('compares primitive transport values without coercing distinct scalar types', () => {
    const cases: [Json | undefined, Json | undefined, boolean][] = [
      [undefined, undefined, true],
      [undefined, null, false],
      [null, null, true],
      [null, false, false],
      [false, 0, false],
      [true, true, true],
      [0, -0, true],
      [1, '1', false],
      ['', '', true],
      ['0', false, false],
      [2.5, 2.6, false],
      ['draft', 'different', false],
      [{ value: 1 }, 1, false],
      [null, [], false],
    ];
    for (const [left, right, expected] of cases)
      expect(equal(left, right)).toBe(expected);
  });

  it('tracks nested object and array edits even when comparing the same reactive object', async () => {
    const value = Alpine.reactive({
      nested: { title: 'first' },
      items: ['first'],
    });
    let runs = 0;
    const runner = Alpine.effect(() => {
      runs++;
      expect(equal(value, value)).toBe(true);
    });
    try {
      value.nested.title = 'second';
      await Promise.resolve();
      expect(runs).toBe(2);
      value.items[0] = 'second';
      await Promise.resolve();
      expect(runs).toBe(3);
    } finally {
      Alpine.release(runner);
    }
  });

  it.each([
    [null, ''],
    [undefined, ''],
    [false, 'false'],
    [0, '0'],
    ['', ''],
    ['draft', 'draft'],
    [{ title: 'draft' }, '{"title":"draft"}'],
    [['one', 'two'], '["one","two"]'],
  ])(
    'restores text controls without losing JSON values: %j',
    (value, expected) => {
      const input = document.createElement('input');
      refreshControl(input, 'value', value === undefined ? {} : { value });
      expect(input.value).toBe(expected);
    },
  );
  it('preserves native JSON distinctions and rejects unsafe values', () => {
    for (const value of [
      null,
      false,
      0,
      '',
      [1, null],
      { nested: { enabled: false } },
      Number.MAX_SAFE_INTEGER,
      -Number.MAX_SAFE_INTEGER,
      1.5,
      '9007199254740993',
    ])
      expect(json(value)).toBe(true);
    for (const value of [
      undefined,
      NaN,
      Infinity,
      Number.MAX_SAFE_INTEGER + 1,
      -Number.MAX_SAFE_INTEGER - 1,
      1e20,
      { id: Number.MAX_SAFE_INTEGER + 1 },
      () => 0,
      JSON.parse('{"__proto__":1}'),
      { constructor: 'bad' },
      new Date(),
      new Map(),
    ])
      expect(json(value)).toBe(false);
    let deep: unknown = null;
    for (let depth = 0; depth < 35; depth++) deep = { child: deep };
    expect(json(deep)).toBe(false);
    expect(record([])).toBe(false);
    expect(state({ a: 0 })).toBe(true);
    expect(state(null)).toBe(false);
  });
  it('retains named edits when PHP initially serialized a form as an empty array', () => {
    const data: State = { form: [], mixed: ['first'] };
    write(data, 'form.title', 'draft');
    write(data, 'mixed.caption', 'caption');
    write(data, 'form.rows.0.title', 'row');
    expect(clone(data)).toEqual({
      form: { title: 'draft', rows: [{ title: 'row' }] },
      mixed: { 0: 'first', caption: 'caption' },
    });
  });
  it('validates metadata and response envelopes', () => {
    expect(writable({ title: { type: 'string', nullable: false } })).toBe(true);
    for (const value of [
      null,
      { constructor: { type: 'string', nullable: true } },
      { x: {} },
      { x: { type: 'object', nullable: false } },
    ])
      expect(writable(value)).toBe(false);
    const payload = {
      version: 1,
      id: 'id',
      html: '<div></div>',
      snapshot: 'signed',
      state: {},
      writable: {},
      errors: { title: ['invalid'] },
      events: [{ name: 'article-saved', detail: {} }],
    };
    expect(response(payload)).toBe(true);
    expect(
      response({ ...payload, listeners: { 'saved:42': ['refresh'] } }),
    ).toBe(true);
    expect(
      response({ ...payload, listeners: { 'saved:{id}': ['refresh'] } }),
    ).toBe(false);
    expect(response({ ...payload, listeners: { 'saved:42': [] } })).toBe(false);
    for (const change of [
      { version: 2 },
      { snapshot: '' },
      { state: [] },
      { errors: { a: [7] } },
      { events: [{}] },
      { events: [{ name: 'invalid name', detail: {} }] },
    ])
      expect(response({ ...payload, ...change })).toBe(false);
  });
  it('safely reads and writes nested paths and arrays', () => {
    const data: State = { count: 0, form: { title: '' }, list: [false] };
    write(data, 'form.title', 'hello');
    write(data, 'form.tags.0', 'tag');
    write(data, 'new.value', null);
    expect(read(data, 'form.title')).toBe('hello');
    expect(read(data, 'form.tags.0')).toBe('tag');
    expect(read(data, 'list.0')).toBe(false);
    expect(read(data, 'missing.value')).toBeUndefined();
    expect(read(data, 'count.value')).toBeUndefined();
    expect(() => write(data, 'count.value', 1)).toThrow('scalar');
    expect(() => path('a..b')).toThrow();
    expect(() => path('form.__proto__.value')).toThrow();
    expect(clone(data)).toEqual(data);
    expect(clone(data)).not.toBe(data);
    expect(equal(undefined, null)).toBe(false);
    expect(() => clone({ x: NaN })).toThrow();
    expect(() => clone(JSON.parse('{"constructor":{}}'))).toThrow(
      'Invalid state',
    );
  });
});
