import { afterEach, expect, it } from 'vite-plus/test';
import { preserveSelection } from '../../resources/ts/dom/selection';

afterEach(() => {
  document.body.innerHTML = '';
});

it('restores the caret after applying a newer draft to a surviving input', () => {
  const root = document.createElement('div');
  const input = document.createElement('input');
  root.append(input);
  document.body.append(root);
  input.value = 'newer draft';
  input.focus();
  input.setSelectionRange(2, 5, 'backward');
  const restore = preserveSelection(root);
  input.value = 'server';
  input.value = 'newer draft';
  restore();
  expect(document.activeElement).toBe(input);
  expect([
    input.selectionStart,
    input.selectionEnd,
    input.selectionDirection,
  ]).toEqual([2, 5, 'backward']);
});

it('ignores removed controls, non-text inputs and focus outside the island', () => {
  const root = document.createElement('div');
  const input = document.createElement('input');
  root.append(input);
  document.body.append(root);
  expect(() => preserveSelection(root)()).not.toThrow();
  input.type = 'number';
  input.focus();
  expect(() => preserveSelection(root)()).not.toThrow();
  input.type = 'text';
  input.focus();
  const restore = preserveSelection(root);
  input.remove();
  expect(() => restore()).not.toThrow();
});
