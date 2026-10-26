import type { State } from '../types';

export type UrlArray =
  | (string | UrlArray)[]
  | { [key: string]: string | UrlArray };
export type UrlValue = string | number | boolean | null | UrlArray;

/** Compiled query capability; values are independently validated at network boundaries. */
export interface UrlField {
  name: string;
  type: 'string' | 'int' | 'float' | 'bool' | 'array';
  default: UrlValue;
  history: boolean;
  nullable?: boolean;
}
export type UrlFields = Record<string, UrlField>;

/** A complete URL selection made by browser navigation, separate from ordinary drafts. */
export interface UrlRestoration {
  values: State;
  epoch: number;
}
