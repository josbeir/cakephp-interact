/** Public URLs and the separate GitHub Pages origin used by the website proxy. */
export const publicBase = '/docs/';
export const publicUrl = `https://www.cakeinteract.dev${publicBase}`;
export const originBase = '/cakephp-interact/';
export const originBuildBase = `${originBase}_docs/`;
export const pagesOutput = 'build/docs-pages';

/** Redirect direct origin visits, including legacy URLs handled by the root 404. */
export const originRedirect = `
if (location.hostname === 'josbeir.github.io' && location.pathname.startsWith(${JSON.stringify(originBase)})) {
  const path = location.pathname.slice(${originBase.length}).replace(/^_docs(?:\\/|$)/, '').replace(/(^|\\/)index\\.html$/, '$1');
  location.replace(${JSON.stringify(publicUrl)} + path + location.search + location.hash);
}
`;
