import type { Alpine } from 'alpinejs';
import type { IslandClient } from '../client';
import { modelBinding } from './model';
import { pollBinding } from './poll';
import { customModel } from './custom-model';
import { defaultTiming } from '../configuration';
import type { TimingDefaults } from '../configuration';

/**
 * Register Interact's public directives and bridge on the host Alpine instance.
 *
 * Alpine owns expression evaluation and directive cleanup. Interact resolves the
 * nearest island and retains native model conversion and submission protection.
 */
export function registerBindings(
  Alpine: Alpine,
  resolve: (element: HTMLElement) => IslandClient,
  pageBinding?: (
    element: HTMLElement,
    value: string,
    modifiers: string[],
    expression: string,
  ) => () => void,
  defaults: TimingDefaults = defaultTiming,
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
        if ((value === 'navigate' || value === 'persist') && pageBinding) {
          cleanup(pageBinding(element, value, modifiers, expression));
          return;
        }
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
          const binding = modelBinding(
            expression,
            modifiers,
            defaults.debounce,
          );
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
        } else if (value === 'stream') {
          cleanup(
            client.streams.bind(
              element,
              expression,
              modifiers,
              Alpine.prefixed(''),
              (fragment, replace) => {
                Alpine.mutateDom(() => {
                  if (replace) {
                    const previous = document.createElement('div');
                    previous.append(...element.childNodes);
                    Alpine.destroyTree(previous);
                    element.replaceChildren();
                  }
                  if (
                    fragment.childNodes.length === 1 &&
                    fragment.firstChild instanceof Text &&
                    element.lastChild instanceof Text
                  ) {
                    element.lastChild.appendData(fragment.firstChild.data);
                    return;
                  }
                  const children = [...fragment.children];
                  element.append(fragment);
                  for (const child of children)
                    if (child instanceof HTMLElement) Alpine.initTree(child);
                });
              },
            ),
          );
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
            client.bindPoll(
              element,
              pollBinding(modifiers, defaults.pollInterval),
              () =>
                expression.trim()
                  ? Alpine.evaluateRaw<unknown>(element, expression)
                  : client.bridge.sync(),
            ),
          );
        } else
          throw new Error(
            'Unknown x-interact directive. Use model, submit, poll, stream, ignore, navigate, or persist.',
          );
      },
    ),
  ).before('modelable');
}
