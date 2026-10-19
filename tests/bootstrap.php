<?php
declare(strict_types=1);

use Cake\Core\Plugin;
use Cake\Routing\Router;
use Interact\InteractPlugin;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/test_app/config/bootstrap.php';

Plugin::getCollection()->add(new InteractPlugin(['path' => dirname(__DIR__) . '/']));
Router::reload();
Router::fullBaseUrl('http://localhost');
Router::createRouteBuilder('/')->connect(
    '/_interact/update',
    ['plugin' => 'Interact', 'controller' => 'Update', 'action' => 'update'],
    ['_name' => 'interact:update'],
);
