import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

/** Prepare the same pinned image used by composer preview before starting workers. */
export default async function setup(): Promise<void> {
  await promisify(execFile)('php', ['scripts/preview.php', 'build'], {
    maxBuffer: 4 * 1024 * 1024,
  });
}
