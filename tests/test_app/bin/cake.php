<?php
declare(strict_types=1);

use Cake\Console\CommandRunner;
use TestApp\Application;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

exit(new CommandRunner(new Application(dirname(__DIR__) . '/config/'))->run($argv));
