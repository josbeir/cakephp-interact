/** Resolve the nearest island owner from the current DOM, including moved and detached nodes. */
export function ownsIslandElement(
  root: HTMLElement,
  element: HTMLElement,
): boolean {
  return (
    root.contains(element) && element.closest('[data-interact-id]') === root
  );
}
