<?php
declare(strict_types=1);

namespace TestApp\Development;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Owns the pinned development container shared by preview and browser acceptance tests.
 *
 * Each instance uses explicit storage and ephemeral hub credentials. Container operations
 * accept only names created by this launcher, keeping teardown away from unrelated apps.
 */
class FrankenPhp
{
    protected const string IMAGE = 'interact-demo:frankenphp-1.13.3';

    /**
     * Keep repository mounts explicit without booting the demo application on the host.
     *
     * @param non-empty-string $root Absolute repository root.
     */
    public function __construct(protected readonly string $root) {}

    /**
     * Build the same pinned PHP/Intl image for preview and acceptance tests.
     */
    public function build(): void
    {
        new Process(
            ['docker', 'build', '--tag', static::IMAGE, 'tests/test_app'],
            $this->root,
            timeout: null,
        )->mustRun();
    }

    /**
     * Start CakePHP and bundled Mercure with worker-owned storage and a stable port.
     *
     * @return PreviewContainer Non-secret handle for the caller's lifecycle.
     */
    public function start(string $storage, int $port = 0): PreviewContainer
    {
        if (!is_dir($storage) && !mkdir($storage, 0o755, true)) {
            throw new RuntimeException('Cannot create preview storage.');
        }

        $storage = realpath($storage);
        if ($storage === false || $port < 0 || $port > 65_535) {
            throw new InvalidArgumentException('Invalid preview storage or port.');
        }

        $port = $port === 0 ? $this->reservePort() : $port;
        $name = 'interact-frankenphp-' . bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $audience = 'https://interact.demo/.well-known/mercure';
        $environment = [
            'INTERACT_DEMO_TMP' => '/tmp/interact-demo',
            'MERCURE_URL' => 'http://127.0.0.1:8765/.well-known/mercure',
            'MERCURE_PUBLIC_URL' => '/.well-known/mercure',
            'MERCURE_JWT_SECRET' => $secret,
            'MERCURE_JWT_ISSUER' => 'https://localhost',
            'MERCURE_JWT_AUDIENCE' => $audience,
            'MERCURE_PUBLISHER_JWT_KEY' => $secret,
            'MERCURE_SUBSCRIBER_JWT_KEY' => $secret,
            'MERCURE_TRUSTED_ISSUERS' => 'https://localhost',
            'MERCURE_RESOURCE_IDENTIFIER' => $audience,
            'XDG_CONFIG_HOME' => '/tmp/interact-caddy/config',
            'XDG_DATA_HOME' => '/tmp/interact-caddy/data',
        ];
        $arguments = [
            'create',
            '--name',
            $name,
            '--publish',
            '127.0.0.1:' . $port . ':8765',
            '--volume',
            $this->root . ':/app:ro',
            '--volume',
            $storage . ':/tmp/interact-demo',
        ];
        if (function_exists('posix_getuid') && function_exists('posix_getgid')) {
            $arguments[] = '--user';
            $arguments[] = posix_getuid() . ':' . posix_getgid();
        }

        foreach (array_keys($environment) as $key) {
            $arguments[] = '--env';
            $arguments[] = $key;
        }

        $arguments[] = static::IMAGE;
        new Process(
            ['docker', ...$arguments],
            $this->root,
            array_merge(getenv(), $environment),
            timeout: null,
        )->mustRun();
        $container = new PreviewContainer($name);
        try {
            new Process(['docker', 'start', $name], timeout: null)->mustRun();
            $container->ready();

            return $container;
        } catch (Throwable $throwable) {
            $container->stop();
            throw $throwable;
        }
    }

    /**
     * Reserve a concrete loopback port so Docker retains it across restarts.
     */
    protected function reservePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Cannot reserve a preview port.');
        }

        try {
            $address = stream_socket_get_name($socket, false);
            $port = $address === false ? null : parse_url('tcp://' . $address, PHP_URL_PORT);
            if (!is_int($port)) {
                throw new RuntimeException('Invalid preview port.');
            }

            return $port;
        } finally {
            fclose($socket);
        }
    }
}
