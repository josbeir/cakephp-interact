import type { RequestResult } from '../types';

/**
 * Server-issued file metadata, without temporary paths or file contents.
 */
export interface UploadedFileInfo {
  id: string;
  name: string;
  size: number;
}

/**
 * Declared file fields and their accepted selections from a verified render.
 */
export type UploadMetadata = Record<
  string,
  {
    multiple: boolean;
    maxSize: number;
    files: UploadedFileInfo[];
  }
>;

/**
 * One local selection, with a stable identity throughout transfer and morphing.
 */
export interface UploadItem extends UploadedFileInfo {
  status: 'queued' | 'uploading' | 'ready' | 'failed';
  progress: number | null;
}

/**
 * Reactive feedback and explicit controls for one declared upload field.
 */
export interface UploadField {
  readonly pending: boolean;
  readonly progress: number | null;
  files: UploadItem[];
  cancel(id?: string): void;
  remove(id?: string): Promise<RequestResult>;
  retry(id?: string): Promise<RequestResult>;
}

/**
 * Internal queue work; raw files stay in browser memory rather than JSON state.
 */
export type UploadJob =
  | {
      kind: 'stage';
      field: string;
      localId: string;
      file: File;
      signal: AbortSignal;
    }
  | { kind: 'remove'; field: string; id?: string };

/**
 * Replaceable boundary for controlled progress and cancellation tests.
 */
export type Uploader = (
  endpoint: string,
  csrf: string,
  payload: unknown,
  file: File,
  signal: AbortSignal,
  progress: (value: number | null) => void,
) => Promise<Response>;
