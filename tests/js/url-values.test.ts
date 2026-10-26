import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import { record, json } from '../../resources/ts/state/values';
import {
  readQuery,
  sameFields,
  urlFields,
  urlValue,
  writeQuery,
} from '../../resources/ts/url/codec';

const fixture: unknown = JSON.parse(
  readFileSync('tests/Fixture/url-values.json', 'utf8'),
);
if (
  !record(fixture) ||
  !record(fixture.fields) ||
  !Array.isArray(fixture.cases)
)
  throw new Error('Invalid shared fixture.');
const definitions = fixture.fields;
const examples: unknown[] = fixture.cases;

describe('shared PHP/browser URL values', () => {
  for (const example of examples) {
    if (
      !record(example) ||
      typeof example.name !== 'string' ||
      typeof example.property !== 'string' ||
      typeof example.query !== 'string' ||
      !json(example.expected)
    )
      throw new Error('Invalid shared query example.');
    const declaration = definitions[example.property];
    if (!record(declaration))
      throw new Error('Missing shared query declaration.');
    const fields: unknown = {
      value: { ...declaration, name: 'value', history: false },
    };
    if (!urlFields(fields))
      throw new Error('Invalid shared query declaration.');
    const { query, expected } = example;
    it(example.name, () => {
      expect(readQuery(query, fields)).toEqual({ value: expected });
      const url = new URL('https://example.test/?external=keep#results');
      writeQuery(url, fields, { value: expected });
      expect(readQuery(url.search, fields)).toEqual({ value: expected });
      expect(url.searchParams.get('external')).toBe('keep');
      expect(url.hash).toBe('#results');
    });
  }
});

it('rejects invalid nested values and nullable metadata before claiming URL state', () => {
  const unsafe: unknown = JSON.parse('{"__proto__":"bad"}');
  for (const value of [
    { a: 1 },
    { a: null },
    { a: false },
    { 'a.b': '' },
    { a: {} },
    unsafe,
  ])
    expect(urlValue(value, 'array')).toBe(false);
  expect(
    urlFields({
      value: {
        name: 'q',
        type: 'array',
        default: [],
        history: false,
        nullable: true,
      },
    }),
  ).toBe(false);
  expect(
    urlFields({
      value: {
        name: 'q',
        type: 'string',
        default: '',
        history: false,
        nullable: 'true',
      },
    }),
  ).toBe(false);
  expect(urlValue(null, 'invalid', true)).toBe(false);
  expect(urlValue(null, 'array', true)).toBe(false);
  expect(
    sameFields(
      { value: { name: 'q', type: 'string', default: '', history: false } },
      {
        value: {
          name: 'q',
          type: 'string',
          default: '',
          history: false,
          nullable: true,
        },
      },
    ),
  ).toBe(false);
});

it('bounds query and accepted-value depth without allocating sparse structures', () => {
  const fields = {
    value: { name: 'q', type: 'array', default: ['default'], history: false },
  };
  if (!urlFields(fields)) throw new Error('Invalid test declaration.');
  expect(readQuery('q=' + 'x'.repeat(1_048_576), fields)).toEqual({
    value: ['default'],
  });
  let value: unknown = 'leaf';
  for (let i = 0; i < 33; i++) value = [value];
  expect(urlValue(value, 'array')).toBe(false);
});
