import type { UploadMetadata } from './types';
import { forbidden, record } from '../state/values';

/**
 * Validate upload metadata before it can select fields or identify browser items.
 */
export function uploadMetadata(value: unknown): value is UploadMetadata {
  return (
    record(value) &&
    Object.entries(value).every(
      ([field, entry]) =>
        !forbidden.has(field) &&
        record(entry) &&
        typeof entry.multiple === 'boolean' &&
        typeof entry.maxSize === 'number' &&
        Number.isSafeInteger(entry.maxSize) &&
        entry.maxSize > 0 &&
        Array.isArray(entry.files) &&
        entry.files.every(
          (file: unknown) =>
            record(file) &&
            typeof file.id === 'string' &&
            /^[a-f0-9]{32}$/.test(file.id) &&
            typeof file.name === 'string' &&
            typeof file.size === 'number' &&
            Number.isSafeInteger(file.size) &&
            file.size >= 0,
        ) &&
        (entry.multiple || entry.files.length <= 1) &&
        new Set(entry.files.map((file) => file.id)).size === entry.files.length,
    )
  );
}
