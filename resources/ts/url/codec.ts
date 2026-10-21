import { equal, forbidden, record } from '../state/values';
import type { Json, State } from '../types';
import type { UrlField, UrlFields, UrlValue } from './types';

const identifier = /^[a-zA-Z_][a-zA-Z0-9_-]*$/;
export function queryName(name: string): boolean {
  return name === name.trim() && identifier.test(name) && !forbidden.has(name);
}

/** Check a value against the intentionally small, lossless query vocabulary. */
export function urlValue(value: unknown, type: string): value is UrlValue {
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
      return (
        Array.isArray(value) &&
        value.every((entry: unknown) => typeof entry === 'string')
      );
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
      !urlValue(field.default, field.type) ||
      typeof field.history !== 'boolean'
    )
      return false;
    names.add(field.name);
    return true;
  });
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
        equal(field.default, other.default)
      );
    })
  );
}

/** Decode an owned field; malformed values independently restore its declared default. */
export function readField(params: URLSearchParams, field: UrlField): Json {
  let scalar: string | undefined;
  const items = new Map<string, string>();
  let size = 0;
  let list = false;
  let invalid = false;
  for (const [key, value] of params) {
    if (key === field.name) scalar = value;
    else if (key.startsWith(`${field.name}[`)) {
      list = true;
      const match = /^\[(0|[1-9][0-9]*)?\]$/.exec(key.slice(field.name.length));
      if (!match || match[0] !== key.slice(field.name.length)) invalid = true;
      else {
        const index = match[1] ?? String(size);
        items.set(index, value);
        size = Math.max(size, Number(index) + 1);
      }
    }
  }
  if (invalid || (list && scalar !== undefined))
    return structuredClone(field.default);
  if (field.type === 'array') {
    const keys = [...items.keys()];
    if (list && keys.every((key, index) => key === String(index)))
      return [...items.values()];
    return scalar === '' && !list ? [] : structuredClone(field.default);
  }
  if (list || scalar === undefined) return structuredClone(field.default);
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

export function readQuery(search: string, fields: UrlFields): State {
  const params = new URLSearchParams(search);
  return Object.fromEntries(
    Object.entries(fields).map(([property, field]) => [
      property,
      readField(params, field),
    ]),
  );
}

/** Replace owned keys only, keeping unrelated query parameters and list ordering intact. */
export function writeQuery(url: URL, fields: UrlFields, values: State): void {
  for (const [property, field] of Object.entries(fields)) {
    const value = values[property];
    if (!urlValue(value, field.type))
      throw new Error('Invalid accepted URL state.');
    for (const key of [...url.searchParams.keys()])
      if (key === field.name || key.startsWith(`${field.name}[`))
        url.searchParams.delete(key);
    if (equal(value, field.default)) continue;
    if (Array.isArray(value)) {
      if (!value.length) url.searchParams.append(field.name, '');
      value.forEach((item, index) =>
        url.searchParams.append(`${field.name}[${index}]`, String(item)),
      );
    } else
      url.searchParams.append(
        field.name,
        typeof value === 'boolean' ? (value ? '1' : '0') : String(value),
      );
  }
}
