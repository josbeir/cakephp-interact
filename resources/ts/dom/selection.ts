/**
 * Capture a focused text control's selection before morphing and draft reconciliation.
 *
 * The returned callback restores only a surviving control. Removed controls and
 * input types without text selection do not receive focus or caret updates.
 */
export function preserveSelection(root: HTMLElement): () => void {
  const active = document.activeElement;
  if (
    !root.contains(active) ||
    !(
      active instanceof HTMLInputElement ||
      active instanceof HTMLTextAreaElement
    )
  )
    return () => {};
  const start = active.selectionStart;
  const end = active.selectionEnd;
  const direction = active.selectionDirection;
  return () => {
    if (!active.isConnected || start === null || end === null) return;
    active.focus({ preventScroll: true });
    active.setSelectionRange(start, end, direction ?? undefined);
  };
}
