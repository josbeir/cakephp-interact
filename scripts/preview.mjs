import { spawn } from 'node:child_process';
import { createInterface } from 'node:readline';

const server = spawn(
  'php',
  [
    '-d',
    'opcache.enable=0',
    '-d',
    'upload_max_filesize=2M',
    '-d',
    'post_max_size=8M',
    '-S',
    '127.0.0.1:8765',
    '-t',
    'tests/test_app/webroot',
    'tests/test_app/webroot/index.php',
  ],
  { stdio: ['inherit', 'inherit', 'pipe'] },
);

const output = createInterface({ input: server.stderr });
output.on('line', (line) => {
  // Only routine connection notices are filtered; PHP diagnostics stay visible.
  if (
    process.env.CI &&
    /^\[[^\]]+\] (?:127\.0\.0\.1|\[::1\]):\d+ (?:Accepted|Closing)$/.test(line)
  )
    return;
  process.stderr.write(`${line}\n`);
});
server.on('error', (error) => {
  console.error(error.message);
  process.exitCode = 1;
});
server.on('exit', (code, signal) => {
  process.exitCode = code ?? (signal === 'SIGINT' ? 130 : 1);
});
for (const signal of ['SIGINT', 'SIGTERM'])
  process.once(signal, () => server.kill(signal));
