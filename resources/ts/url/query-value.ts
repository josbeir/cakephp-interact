import type { Json } from '../types';
import { queryName } from './arrays';

interface QueryNode {
  scalar?: string;
  children: Map<string, QueryNode>;
}

type QueryValue =
  | { valid: true; present: boolean; value: Json }
  | { valid: false };

/** Insert raw segments without allocating sparse arrays or normalizing map keys. */
function insert(
  node: QueryNode,
  path: string[],
  value: string,
  depth = 0,
): void {
  if (
    depth > 32 ||
    (path.length > 0 && node.scalar !== undefined) ||
    (path.length === 0 && node.children.size > 0)
  )
    throw new Error('Conflicting or excessive query structure.');
  const segment = path.shift();
  if (segment === undefined) {
    node.scalar = value;
    return;
  }
  const key = segment === '' ? String(node.children.size) : segment;
  if (
    !queryName(key) &&
    !/^(0|[1-9][0-9]*)$/.test(key) &&
    key !== '~null' &&
    key !== '~empty'
  )
    throw new Error('Invalid query path.');
  let child = node.children.get(key);
  if (!child) {
    child = { children: new Map() };
    node.children.set(key, child);
  }
  insert(child, path, value, depth + 1);
}

/** Decode only dense lists, safe maps, and sole literal null/empty markers. */
function value(node: QueryNode): Json {
  if (node.scalar !== undefined) return node.scalar;
  const entries: [string, Json][] = [];
  let list: boolean | undefined;
  for (const [key, child] of node.children) {
    if (key === '~null' || key === '~empty') {
      if (
        node.children.size !== 1 ||
        child.scalar !== '1' ||
        child.children.size > 0
      )
        throw new Error('Invalid query marker.');
      return key === '~null' ? null : [];
    }
    const numeric = /^[0-9]+$/.test(key);
    list ??= numeric;
    if (list !== numeric || (numeric && key !== String(entries.length)))
      throw new Error('Mixed or sparse query array.');
    entries.push([key, value(child)]);
  }
  return list ? entries.map(([, entry]) => entry) : Object.fromEntries(entries);
}

/** Parse one owned parameter tree independently of malformed unrelated input. */
export function queryValue(params: URLSearchParams, name: string): QueryValue {
  const node: QueryNode = { children: new Map() };
  let present = false;
  try {
    for (const [key, entry] of params) {
      if (key !== name && !key.startsWith(`${name}[`)) continue;
      present = true;
      const suffix = key.slice(name.length);
      if (!/^(?:\[[^[\]]*\])*$/.test(suffix)) return { valid: false };
      const path = [...suffix.matchAll(/\[([^[\]]*)\]/g)].map(
        (match) => match[1] ?? '',
      );
      insert(node, path, entry);
    }
    return { valid: true, present, value: present ? value(node) : null };
  } catch {
    return { valid: false };
  }
}
