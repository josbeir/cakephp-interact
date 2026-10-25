/** Isolated infrastructure handle; credentials belong only to its short-lived test processes. */
export interface MercureHub {
  url: string;
  name: string;
  env: Record<string, string>;
  logs(): Promise<string>;
  restart(): Promise<void>;
  stop(): Promise<void>;
}
export const mercureImage: string;
export function startMercure(): Promise<MercureHub>;
