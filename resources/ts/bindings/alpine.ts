import type { Alpine } from 'alpinejs';
import type { IslandClient } from '../client';
import { modelBinding } from './model';
import { pollBinding } from './poll';
import { customModel } from './custom-model';

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
      (
        element,
        { value, modifiers, expression, original },
        { cleanup, effect },
      ) => {
        const client = resolve(element);
        if (value === 'model') {
          if (
            element.hasAttribute(Alpine.prefixed('model')) ||
            [...element.attributes].some((attribute) =>
              attribute.name.startsWith(`${Alpine.prefixed('model')}.`),
            )
          )
            throw new Error(
              'Use one model binding per control; x-model and x-interact:model cannot be combined.',
            );
          const binding = modelBinding(expression, modifiers);
          const modelable = element.getAttribute(Alpine.prefixed('modelable'));
          if (modelable !== null) {
            cleanup(
              client.bindCustomModel(
                element,
                binding,
                customModel(Alpine, element, modelable),
              ),
            );
            effect(() => client.customChanged(element));
          } else if (element instanceof HTMLFormElement) {
            cleanup(client.bindForm(element, binding, Alpine.prefixed('')));
          } else if (
            element instanceof HTMLInputElement ||
            element instanceof HTMLSelectElement ||
            element instanceof HTMLTextAreaElement
          ) {
            cleanup(client.bindModel(element, binding));
          } else {
            throw new Error(
              'x-interact:model requires an input, select, textarea, form, or x-modelable wrapper.',
            );
          }
        } else if (value === 'ignore') {
          if (element === client.root || modifiers.length || expression.trim())
            throw new Error(
              'x-interact:ignore requires an island descendant without an expression or modifiers.',
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
        } else if (value === 'poll') {
          cleanup(
            client.bindPoll(element, pollBinding(modifiers), () =>
              expression.trim()
                ? Alpine.evaluateRaw<unknown>(element, expression)
                : client.bridge.sync(),
            ),
          );
        } else
          throw new Error(
            'Unknown x-interact directive. Use model, submit, poll, or ignore.',
          );
      },
    ),
  ).before('modelable');
}
