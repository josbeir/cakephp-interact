<?php
declare(strict_types=1);

use TestApp\Development\FrankenPhp;
use TestApp\Development\PreviewContainer;

require dirname(__DIR__) . '/vendor/autoload.php';

$launcher = new FrankenPhp(dirname(__DIR__));
$command = $argv[1] ?? 'preview';
try {
    if ($command === 'build') {
        $launcher->build();
    } elseif ($command === 'start') {
        $storage = $argv[2] ?? throw new InvalidArgumentException('Supply isolated storage.');
        $port = $argv[3] ?? '0';
        if (!ctype_digit($port)) {
            throw new InvalidArgumentException('Invalid preview port.');
        }

        $server = $launcher->start($storage, (int)$port);
        echo json_encode(['name' => $server->name, 'url' => $server->url()], JSON_THROW_ON_ERROR), "\n";
    } elseif (in_array($command, ['logs', 'stop', 'restart'], true)) {
        $name = $argv[2] ?? throw new InvalidArgumentException('Supply a preview container.');
        $container = new PreviewContainer($name);
        if ($command === 'logs') {
            echo $container->logs();
        } elseif ($command === 'stop') {
            $container->stop();
        } else {
            $container->restart();
        }
    } elseif ($command === 'preview') {
        $port = getenv('INTERACT_DEMO_PORT') ?: '8765';
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65_535) {
            throw new InvalidArgumentException('INTERACT_DEMO_PORT must be a TCP port between 1 and 65_535.');
        }

        echo "Preparing the FrankenPHP preview image…\n";
        $launcher->build();
        $server = $launcher->start(getenv('INTERACT_DEMO_TMP') ?: dirname(__DIR__) . '/build/tmp', (int)$port);
        $stopped = false;
        $stop = static function () use (&$stopped, $server): void {
            if (!$stopped) {
                $stopped = true;
                $server->stop();
            }
        };
        register_shutdown_function($stop);
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, static function () use ($stop): never {
                    $stop();
                    exit(0);
                });
            }
        }

        try {
            echo 'Demo: ', $server->url(), "\nRealtime chat: ", $server->url(), "/realtime\n";
            echo 'Share the app and hub together: ngrok http ', $port, "\n";
            $code = $server->wait();
            if ($code !== 0) {
                fwrite(STDERR, $server->logs());
            }
        } finally {
            $stop();
        }

        exit($code);
    } else {
        throw new InvalidArgumentException('Unknown preview command.');
    }
} catch (Exception $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
