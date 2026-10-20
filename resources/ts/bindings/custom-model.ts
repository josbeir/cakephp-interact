import type { Alpine } from 'alpinejs';
import { path } from '../state/paths';
import { clone, json } from '../state/values';
import type { Json } from '../types';

/**
 * Access an application wrapper's public Alpine model without relying on Alpine internals.
 *
 * Reads are synchronous so submitting in the same turn as a widget edit captures
 * the latest value. Copies prevent mutable widget values from aliasing island drafts.
 */
export interface CustomModel {
  read(): Json | undefined;
  write(value: Json | undefined): void;
}

/**
 * Resolve a modelable property through the host Alpine evaluator, including its CSP build.
 */
export function customModel(
  Alpine: Alpine,
  element: HTMLElement,
  expression: string,
): CustomModel {
  path(expression);
  if (!/^[a-zA-Z_]\w*(?:\.[a-zA-Z_]\w*)*$/.test(expression))
    throw new Error('x-modelable requires a property path.');
  return {
    read() {
      const value = Alpine.evaluateRaw<unknown>(element, expression);
      if (value === undefined) return undefined;
      if (!json(value)) throw new Error('Custom models require JSON values.');
      return clone({ value }).value;
    },
    write(value) {
      Alpine.evaluateRaw<unknown>(element, `${expression} = __interactValue`, {
        scope: {
          __interactValue:
            value === undefined ? undefined : clone({ value }).value,
        },
      });
    },
  };
}
