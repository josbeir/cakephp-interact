import { readFileSync } from 'node:fs';
const report = JSON.parse(
  readFileSync('build/coverage-js/coverage-summary.json', 'utf8'),
);
const { covered, total } = report.total.lines;
if (
  !Number.isSafeInteger(total) ||
  !Number.isSafeInteger(covered) ||
  total <= 0 ||
  covered < 0 ||
  covered > total ||
  covered * 100 < total * 95
) {
  throw new Error(
    'JavaScript line coverage must measure executable source and reach at least 95%.',
  );
}
console.log(`JavaScript coverage: ${covered}/${total} lines; 95% gate passed.`);
