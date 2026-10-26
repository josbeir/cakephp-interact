import { afterEach, expect, it } from 'vite-plus/test';
import { ownsIslandElement } from '../../../resources/ts/dom/ownership';

afterEach(() => document.body.replaceChildren());

function island(): HTMLDivElement {
  const root = document.createElement('div');
  root.dataset.interactId = 'island';
  return root;
}

it('includes its root and descendants while excluding unrelated and nested islands', () => {
  const parent = island();
  const child = island();
  const input = document.createElement('input');
  const nested = document.createElement('span');
  parent.append(input, child);
  child.append(nested);
  document.body.append(parent);
  expect(ownsIslandElement(parent, parent)).toBe(true);
  expect(ownsIslandElement(parent, input)).toBe(true);
  expect(ownsIslandElement(parent, child)).toBe(false);
  expect(ownsIslandElement(parent, nested)).toBe(false);
  expect(ownsIslandElement(child, nested)).toBe(true);
  expect(ownsIslandElement(island(), input)).toBe(false);
});

it('reads ownership again after moves and leaves connectivity to the caller', () => {
  const first = island();
  const second = island();
  const input = document.createElement('input');
  first.append(input);
  document.body.append(first, second);
  expect(ownsIslandElement(first, input)).toBe(true);
  second.append(input);
  expect(ownsIslandElement(first, input)).toBe(false);
  expect(ownsIslandElement(second, input)).toBe(true);
  second.remove();
  expect(input.isConnected).toBe(false);
  expect(ownsIslandElement(second, input)).toBe(true);
  input.remove();
  expect(ownsIslandElement(second, input)).toBe(false);
});
