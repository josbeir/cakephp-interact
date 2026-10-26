import { equal, record } from '../state/values';
import type { Json, State } from '../types';
import type { UrlField, UrlFields, UrlValue } from './types';

import { mergeMaps, queryName, stringTree } from './arrays';
import { queryValue } from './query-value';
export { queryName } from './arrays';

/** Check a value against the intentionally small, lossless query vocabulary. */
export function urlValue(
  value: unknown,
  type: string,
  nullable = false,
): value is UrlValue {
  if (value === null)
    return nullable && ['string', 'int', 'float', 'bool'].includes(type);
  switch (type) {
    case 'string':
      return typeof value === 'string';
    case 'bool':
      return typeof value === 'boolean';
    case 'int':
      return typeof value === 'number' && Number.isSafeInteger(value);
    case 'float':
      return typeof value === 'number' && Number.isFinite(value);
    case 'array':
      return typeof value !== 'string' && stringTree(value);
    default:
      return false;
  }
}

/** Reject malformed declaration metadata before claiming any page parameters. */
export function urlFields(value: unknown): value is UrlFields {
  if (!record(value)) return false;
  const names = new Set<string>();
  return Object.entries(value).every(([property, field]) => {
    if (
      !queryName(property) ||
      !record(field) ||
      typeof field.name !== 'string' ||
      !queryName(field.name) ||
      names.has(field.name) ||
      typeof field.type !== 'string' ||
      (field.nullable !== undefined && typeof field.nullable !== 'boolean') ||
      (field.type === 'array' && field.nullable === true) ||
      !urlValue(field.default, field.type, field.nullable === true) ||
      typeof field.history !== 'boolean'
    )
      return false;
    names.add(field.name);
    return true;
  });
}

/** Require every declared query property to be present with its supported value type. */
export function urlState(values: State, fields: UrlFields): boolean {
  return Object.entries(fields).every(([property, field]) =>
    urlValue(values[property], field.type, field.nullable === true),
  );
}

/** Compare declarations without depending on JSON object key order. */
export function sameFields(left: UrlFields, right: UrlFields): boolean {
  return (
    Object.keys(left).length === Object.keys(right).length &&
    Object.entries(left).every(([property, field]) => {
      const other = right[property];
      return (
        other !== undefined &&
        field.name === other.name &&
        field.type === other.type &&
        field.history === other.history &&
        (field.nullable === true) === (other.nullable === true) &&
        equal(field.default, other.default)
      );
    })
  );
}

/** Decode an owned field; malformed values independently restore its declared default. */
export function readField(params: URLSearchParams, field: UrlField): Json {
  const raw = queryValue(params, field.name);
  if (!raw.valid || !raw.present) return structuredClone(field.default);
  if (field.type === 'array') {
    const tree = raw.value === '' ? [] : raw.value;
    return typeof tree !== 'string' &&
      stringTree(tree) &&
      typeof field.default !== 'string' &&
      stringTree(field.default)
      ? mergeMaps(tree, field.default)
      : structuredClone(field.default);
  }
  if (raw.value === null && field.nullable === true) return null;
  if (typeof raw.value !== 'string') return structuredClone(field.default);
  const scalar = raw.value;
  let value: Json = scalar;
  if (field.type === 'bool')
    value = scalar === '1' ? true : scalar === '0' ? false : null;
  else if (field.type === 'int')
    value =
      scalar === scalar.trim() && /^-?(0|[1-9][0-9]*)$/.test(scalar)
        ? Number(scalar)
        : null;
  else if (field.type === 'float')
    value =
      scalar === scalar.trim() &&
      /^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?$/.test(scalar)
        ? Number(scalar)
        : null;
  return urlValue(value, field.type) ? value : structuredClone(field.default);
}

/** Identify malformed UTF-8 before URLSearchParams replaces invalid octets with U+FFFD. */
function malformedKeys(search: string): Set<string> {
  const invalid = new Set<string>();
  const query = search.startsWith('?') ? search.slice(1) : search;
  for (const pair of query.split('&')) {
    try {
      // Literal percent signs remain valid text, matching PHP's urldecode().
      decodeURIComponent(pair.replace(/%(?![a-f0-9]{2})/gi, '%25'));
    } catch {
      // A leading separator prevents a literal question mark becoming a query prefix.
      for (const key of new URLSearchParams(`&${pair}`).keys())
        invalid.add(key);
    }
  }
  return invalid;
}

export function readQuery(search: string, fields: UrlFields): State {
  if (new TextEncoder().encode(search).length > 1_048_576)
    return Object.fromEntries(
      Object.entries(fields).map(([property, field]) => [
        property,
        structuredClone(field.default),
      ]),
    );
  const params = new URLSearchParams(search);
  const invalid = malformedKeys(search);
  return Object.fromEntries(
    Object.entries(fields).map(([property, field]) => [
      property,
      [...invalid].some(
        (key) => key === field.name || key.startsWith(`${field.name}[`),
      )
        ? structuredClone(field.default)
        : readField(params, field),
    ]),
  );
}

/** Replace owned keys only, keeping unrelated query parameters and list ordering intact. */
export function writeQuery(url: URL, fields: UrlFields, values: State): void {
  for (const [property, field] of Object.entries(fields)) {
    const value = values[property];
    if (!urlValue(value, field.type, field.nullable === true))
      throw new Error('Invalid accepted URL state.');
    for (const key of [...url.searchParams.keys()])
      if (key === field.name || key.startsWith(`${field.name}[`))
        url.searchParams.delete(key);
    if (equal(value, field.default)) continue;
    appendValue(url.searchParams, field.name, value);
  }
}

/** Preserve nulls and empty nested arrays without changing ordinary string values. */
function appendValue(
  params: URLSearchParams,
  name: string,
  value: UrlValue,
  depth = 0,
): void {
  if (value === null) {
    params.append(`${name}[~null]`, '1');
  } else if (typeof value === 'object') {
    const entries = Object.entries(value);
    if (entries.length === 0)
      params.append(
        depth === 0 ? name : `${name}[~empty]`,
        depth === 0 ? '' : '1',
      );
    for (const [key, entry] of entries)
      appendValue(params, `${name}[${key}]`, entry, depth + 1);
  } else
    params.append(
      name,
      typeof value === 'boolean' ? (value ? '1' : '0') : String(value),
    );
}
