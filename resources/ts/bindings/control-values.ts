import { path, read, write } from '../state/paths';
import type { Json, State, Writable } from '../types';

/**
 * Native controls supported by declarative island model bindings.
 */
export type Control =
  | HTMLInputElement
  | HTMLSelectElement
  | HTMLTextAreaElement;

/**
 * Read a native control into its writable property without sending a request.
 *
 * Checkbox groups and numeric controls use the declared PHP type metadata.
 * Application validation and persistence remain server responsibilities.
 *
 * @throws Error For a locked property, unsafe path, or unsupported file binding.
 */
export function captureControl(
  control: Control,
  name: string,
  current: State,
  fields: Writable,
  list = false,
): void {
  const first = path(name)[0];
  const field =
    first === undefined ? undefined : (fields[name] ?? fields[first]);
  if (!field) throw new Error('This state is not writable.');
  let value: Json = control.value;
  if (control instanceof HTMLInputElement) {
    if (control.type === 'file')
      throw new Error('File binding is not supported.');
    if (control.type === 'radio' && !control.checked) return;
    if (control.type === 'checkbox') {
      if (
        list ||
        (field.type === 'array' && path(name).length === 1) ||
        Array.isArray(read(current, name))
      ) {
        const selected = read(current, name);
        const values = Array.isArray(selected)
          ? selected.filter((entry) => entry !== control.value)
          : [];
        value = control.checked ? [...values, control.value] : values;
      } else value = control.checked;
    }
  }
  if (control instanceof HTMLSelectElement && control.multiple)
    value = Array.from(control.selectedOptions, (option) => option.value);
  captureValue(value, name, current, fields);
}

/**
 * Apply native numeric conversion and writable-path checks to any model source.
 */
export function captureValue(
  value: Json,
  name: string,
  current: State,
  fields: Writable,
): void {
  const first = path(name)[0];
  const field =
    first === undefined ? undefined : (fields[name] ?? fields[first]);
  if (!field) throw new Error('This state is not writable.');
  if (field.type === 'int' || field.type === 'float') {
    if (value === '' && field.nullable) value = null;
    else if (
      typeof value === 'string' &&
      value.trim() !== '' &&
      Number.isFinite(Number(value)) &&
      (field.type !== 'int' || Number.isSafeInteger(Number(value)))
    )
      value = Number(value);
  }
  write(current, name, value);
}

/**
 * Restore a surviving control from reconciled state after a server render.
 */
export function refreshControl(
  control: Control,
  name: string,
  current: State,
): void {
  const value = read(current, name);
  if (
    control instanceof HTMLInputElement &&
    ['checkbox', 'radio'].includes(control.type)
  )
    control.checked = Array.isArray(value)
      ? value.includes(control.value)
      : control.type === 'radio'
        ? value === control.value
        : value === true;
  else if (control instanceof HTMLSelectElement && control.multiple)
    for (const option of control.options)
      option.selected = Array.isArray(value) && value.includes(option.value);
  else if (!(control instanceof HTMLInputElement && control.type === 'file'))
    control.value =
      value === null || value === undefined
        ? ''
        : typeof value === 'object'
          ? JSON.stringify(value)
          : String(value);
}
