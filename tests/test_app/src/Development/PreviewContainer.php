<?php
declare(strict_types=1);

namespace TestApp\Development;

use Cake\Http\Client;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Controls one development app and its bundled hub after Docker creates it.
 *
 * Names are restricted to this launcher's containers. Health checks gate startup
 * and restart; callers collect diagnostics before releasing temporary storage.
 */
class PreviewContainer
{
    /**
     * Validate ownership before allowing lifecycle operations on a local container.
     */
    public function __construct(public readonly string $name)
    {
        if (!preg_match('/^interact-frankenphp-[a-f0-9]{32}$/D', $name)) {
            throw new InvalidArgumentException('Unknown preview container.');
        }
    }

    /**
     * Restart app and hub together while preserving the published port and mounted storage.
     */
    public function restart(): void
    {
        new Process(['docker', 'restart', '--time', '0', $this->name], timeout: null)->mustRun();
        $this->ready();
    }

    /**
     * Collect combined application and hub diagnostics before teardown.
     */
    public function logs(): string
    {
        $process = new Process(['docker', 'logs', $this->name], timeout: null);
        $process->mustRun();

        return $process->getOutput() . $process->getErrorOutput();
    }

    /**
     * Remove only the caller's container and its temporary Docker volumes.
     */
    public function stop(): void
    {
        new Process(['docker', 'rm', '--force', '--volumes', $this->name], timeout: null)->mustRun();
    }

    /**
     * Keep the interactive preview alive until its server exits.
     */
    public function wait(): int
    {
        $process = new Process(['docker', 'wait', $this->name], timeout: null);
        $process->mustRun();

        return (int)trim($process->getOutput());
    }

    /**
     * Read the published origin without relying on a fixed test port.
     */
    public function url(): string
    {
        $process = new Process(['docker', 'port', $this->name, '8765/tcp'], timeout: null);
        $process->mustRun();

        $address = trim($process->getOutput());
        if (!preg_match('/^127\.0\.0\.1:\d+$/D', $address)) {
            throw new RuntimeException('FrankenPHP did not publish a loopback port.');
        }

        return 'http://' . $address;
    }

    /**
     * Require the real CakePHP app to boot before handing the origin to a browser.
     */
    public function ready(): void
    {
        $url = $this->url();
        $failure = 'No successful response.';
        $client = new Client(['timeout' => 1]);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                if ($client->get($url)->isOk()) {
                    return;
                }
            } catch (Exception $exception) {
                $failure = $exception->getMessage();
            }

            usleep(100_000);
        }

        throw new RuntimeException('FrankenPHP did not become ready: ' . $failure . "\n" . $this->logs());
    }
}
