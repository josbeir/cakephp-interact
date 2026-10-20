/**
 * Describe an advertised byte ceiling using binary units without exposing a raw counter.
 */
export function displaySize(bytes: number): string {
  const units = ['bytes', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
  const exponent = Math.min(
    units.length - 1,
    Math.floor(Math.log2(bytes) / 10),
  );
  const value = bytes / 1024 ** exponent;
  return `${value.toLocaleString(undefined, { maximumFractionDigits: 2 })} ${units[exponent] ?? 'bytes'}`;
}
