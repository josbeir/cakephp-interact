export type AssetPolicy = 'reload' | 'persist' | undefined;

export interface HeadEntry {
  node: Element;
  signature: string;
  policy: AssetPolicy;
}

export function stylesheet(node: Element): node is HTMLLinkElement {
  return node instanceof HTMLLinkElement && node.relList.contains('stylesheet');
}

/** Validate literal head policies before loading assets or changing the active head. */
export function assetPolicy(node: Element, attribute: string): AssetPolicy {
  const declarations = node
    .getAttributeNames()
    .filter((name) => name === attribute || name.startsWith(attribute + '.'));
  if (!declarations.length) return;
  const value = node.getAttribute(attribute);
  if (
    node.parentElement?.localName !== 'head' ||
    declarations.length !== 1 ||
    declarations[0] !== attribute ||
    (value !== 'reload' && value !== 'persist') ||
    (value === 'reload' &&
      !stylesheet(node) &&
      !(node instanceof HTMLScriptElement && node.hasAttribute('src'))) ||
    (value === 'persist' &&
      ((!stylesheet(node) && !(node instanceof HTMLStyleElement)) ||
        !node.id.trim()))
  )
    throw new Error(
      'x-interact:asset requires reload on an external head asset, or persist on a head style with a unique nonempty id, without modifiers.',
    );
  return value;
}

/** Canonicalize attribute order and resource URLs once per head element. */
export function headEntries(
  nodes: Iterable<Element>,
  base: string,
  attribute: string,
): HeadEntry[] {
  const entries: HeadEntry[] = [];
  const ids = new Map<string, number>();
  for (const node of nodes) {
    if (node.id) ids.set(node.id, (ids.get(node.id) ?? 0) + 1);
    const attributes = [...node.attributes]
      .map(({ name, value }) => {
        if (
          name === 'nonce' &&
          'nonce' in node &&
          typeof node.nonce === 'string'
        )
          value = node.nonce;
        if (name === 'src' || name === 'href')
          value = new URL(value, base).href;
        return [name, value];
      })
      .sort(([a = ''], [b = '']) => a.localeCompare(b));
    entries.push({
      node,
      policy: assetPolicy(node, attribute),
      signature: JSON.stringify([node.localName, attributes, node.textContent]),
    });
  }
  if (
    entries.some(
      ({ node, policy }) => policy === 'persist' && ids.get(node.id) !== 1,
    )
  )
    throw new Error('Persistent head styles require unique ids.');
  return entries;
}

/** Compare ordered tracked assets, including additions, removals, and hashed URL changes. */
export function trackedAssets(entries: HeadEntry[]): string {
  return JSON.stringify(
    entries
      .filter((entry) => entry.policy === 'reload')
      .map((entry) => entry.signature),
  );
}
