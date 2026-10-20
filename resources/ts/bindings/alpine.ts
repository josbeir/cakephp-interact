import type { Alpine } from 'alpinejs';
import type { IslandClient } from '../client';
import { modelBinding } from './model';

/**
 * Register Interact's public directives and bridge on the host Alpine instance.
 *
 * Alpine owns expression evaluation and directive cleanup. Interact resolves the
 * nearest island and retains native model conversion and submission protection.
 */
export function registerBindings(
  Alpine: Alpine,
  resolve: (element: HTMLElement) => IslandClient,
): void {
  Alpine.magic('server', (element) => resolve(element).bridge);
  Alpine.directive(
    'interact',
    Alpine.skipDuringClone(
      (element, { value, modifiers, expression, original }, { cleanup }) => {
        const client = resolve(element);
        if (value === 'model') {
          if (
            !(
              element instanceof HTMLInputElement ||
              element instanceof HTMLSelectElement ||
              element instanceof HTMLTextAreaElement
            )
          )
            throw new Error(
              'x-interact:model requires an input, select, or textarea.',
            );
          if (
            element.hasAttribute(Alpine.prefixed('model')) ||
            [...element.attributes].some((attribute) =>
              attribute.name.startsWith(`${Alpine.prefixed('model')}.`),
            )
          )
            throw new Error(
              'Use one model binding per control; x-model and x-interact:model cannot be combined.',
            );
          cleanup(
            client.bindModel(element, modelBinding(expression, modifiers)),
          );
        } else if (value === 'submit') {
          if (
            !(element instanceof HTMLFormElement) ||
            modifiers.length ||
            !expression.trim()
          )
            throw new Error(
              'x-interact:submit requires a form and a non-empty Alpine expression without modifiers.',
            );
          cleanup(
            client.bindSubmit(element, original, expression, (event) =>
              Alpine.evaluateRaw<unknown>(element, expression, {
                scope: { $event: event },
              }),
            ),
          );
        } else
          throw new Error('Unknown x-interact directive. Use model or submit.');
      },
    ),
  );
}
