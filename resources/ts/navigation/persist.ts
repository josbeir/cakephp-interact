import { preserveSelection } from '../dom/selection';

export interface RetainedRegion {
  current: HTMLElement;
  incoming: HTMLElement;
}

/** Validate literal ownership before changing either document. */
export function regions(
  document: Document,
  attribute: string,
): Map<string, HTMLElement> {
  const result = new Map<string, HTMLElement>();
  for (const node of [...document.getElementsByTagName('*')].filter(
    (node): node is HTMLElement =>
      node instanceof HTMLElement && node.hasAttribute(attribute),
  )) {
    const key = node.getAttribute(attribute)?.trim();
    if (
      !key ||
      node === document.body ||
      !document.body.contains(node) ||
      result.has(key) ||
      node.closest('[data-interact-id]') ||
      [...result.values()].some((parent) => parent.contains(node))
    )
      throw new Error(
        'Persistent regions require unique keys outside islands and cannot nest.',
      );
    result.set(key, node);
  }
  return result;
}

/** Match fresh authorized layout mounts without comparing their mutable drafts or signed routes. */
export function retainedRegions(
  current: Document,
  incoming: Document,
  attribute: string,
): RetainedRegion[] {
  const old = regions(current, attribute);
  const next = regions(incoming, attribute);
  const result: RetainedRegion[] = [];
  for (const [key, node] of next) {
    const existing = old.get(key);
    if (!existing || existing.tagName !== node.tagName) continue;
    const before = [
      ...existing.querySelectorAll<HTMLElement>('[data-interact-id]'),
    ];
    const after = [...node.querySelectorAll<HTMLElement>('[data-interact-id]')];
    if (
      before.length !== after.length ||
      before.some(
        (root, index) =>
          !root.dataset.interactMount ||
          root.dataset.interactMount !== after[index]?.dataset.interactMount ||
          root.dataset.interactEndpoint !==
            after[index]?.dataset.interactEndpoint,
      )
    )
      continue;
    result.push({ current: existing, incoming: node });
  }
  return result;
}

/** Capture both scroll axes and focused controls before persistent nodes are moved. */
export function preserveRegions(retained: RetainedRegion[]): () => void {
  const selection = preserveSelection(document.body);
  const active = document.activeElement;
  const focus =
    active instanceof HTMLElement &&
    retained.some(({ current }) => current.contains(active))
      ? active
      : undefined;
  const positions = retained.flatMap(({ current }) =>
    [current, ...current.querySelectorAll<HTMLElement>('*')]
      .filter((node) => node.scrollTop || node.scrollLeft)
      .map((node) => ({ node, x: node.scrollLeft, y: node.scrollTop })),
  );
  return () => {
    focus?.focus({ preventScroll: true });
    if (focus) selection();
    for (const { node, x, y } of positions) {
      node.scrollLeft = x;
      node.scrollTop = y;
    }
  };
}
