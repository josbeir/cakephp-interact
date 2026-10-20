/**
 * Supply the standard Alpine contract for the CSP build's untyped package entry.
 */
declare module '@alpinejs/csp' {
  import type { Alpine } from 'alpinejs';
  const alpine: Alpine;
  export default alpine;
}
