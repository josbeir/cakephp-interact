import { describe, expect, it } from 'vitest';
import {
  clone,
  equal,
  json,
  record,
  state,
} from '../../resources/ts/state/values';
import { path, read, write } from '../../resources/ts/state/paths';
import { response, writable } from '../../resources/ts/transport/protocol';
import type { State } from '../../resources/ts/types';

describe('transport values', () => {
  it('preserves native JSON distinctions and rejects unsafe values', () => {
    for (const value of [
      null,
      false,
      0,
      '',
      [1, null],
      { nested: { enabled: false } },
    ])
      expect(json(value)).toBe(true);
    for (const value of [
      undefined,
      NaN,
      Infinity,
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
