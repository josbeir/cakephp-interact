<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

/** @var RouteBuilder $routes */
$routes->connect(
    '/',
    ['controller' => 'Articles', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:index'],
);
$routes->connect(
    '/events',
    ['controller' => 'Articles', 'action' => 'events'],
    ['_method' => 'GET', '_name' => 'demo:events'],
);
$routes->connect(
    '/playground',
    ['controller' => 'Articles', 'action' => 'playground'],
    ['_method' => 'GET', '_name' => 'demo:playground'],
);
$routes->connect(
    '/forms',
    ['controller' => 'Articles', 'action' => 'forms'],
    ['_method' => 'GET', '_name' => 'demo:forms'],
);
$routes->connect(
    '/demo/form-fixtures',
    ['controller' => 'Articles', 'action' => 'formFixtures'],
    ['_method' => 'GET', '_name' => 'demo:form-fixtures'],
);
$routes->connect(
    '/demo/polling',
    ['controller' => 'Articles', 'action' => 'polling'],
    ['_method' => 'GET', '_name' => 'demo:polling-fixtures'],
);
$routes->connect(
    '/page',
    ['controller' => 'Articles', 'action' => 'page'],
    ['_method' => 'GET', '_name' => 'demo:page'],
);
$routes->connect(
    '/demo/role/{role}',
    ['controller' => 'Articles', 'action' => 'role'],
    ['_method' => 'POST', '_name' => 'demo:role', 'pass' => ['role']],
);
$routes->connect(
    '/demo/redirect',
    ['controller' => 'Articles', 'action' => 'loginRedirect'],
    ['_method' => 'POST', '_name' => 'demo:redirect'],
);
$routes->connect(
    '/demo/debugging',
    ['controller' => 'Debugging', 'action' => 'probe'],
    ['_method' => 'GET', '_name' => 'demo:debugging'],
);
$routes->connect(
    '/recovery/sign-in',
    ['controller' => 'Recovery', 'action' => 'signIn', '_method' => 'GET'],
    ['_name' => 'demo:recovery-sign-in'],
);
$routes->connect(
    '/recovery/sign-in',
    ['controller' => 'Recovery', 'action' => 'completeSignIn', '_method' => 'POST'],
    ['_name' => 'demo:recovery-sign-in-complete'],
);
$routes->connect(
    '/debugging',
    ['controller' => 'Debugging', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:debugging-example'],
);
$routes->connect(
    '/loading',
    ['controller' => 'Loading', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:loading'],
);
$routes->connect(
    '/demo/loading-upload',
    ['controller' => 'Loading', 'action' => 'upload'],
    ['_method' => 'GET', '_name' => 'demo:loading-upload'],
);
$routes->connect(
    '/navigation',
    ['controller' => 'Navigation', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:navigation'],
);
$routes->connect(
    '/navigation/details',
    ['controller' => 'Navigation', 'action' => 'details'],
    ['_method' => 'GET', '_name' => 'demo:navigation-details'],
);
$routes->connect(
    '/navigation/nonces',
    ['controller' => 'Navigation', 'action' => 'nonces'],
    ['_method' => 'GET', '_name' => 'demo:navigation-nonces'],
);
$routes->connect(
    '/navigation/nonces/details',
    ['controller' => 'Navigation', 'action' => 'nonceDetails'],
    ['_method' => 'GET', '_name' => 'demo:navigation-nonces-details'],
);
$routes->connect(
    '/outcomes',
    ['controller' => 'Outcomes', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:outcomes'],
);
$routes->connect(
    '/outcomes/saved',
    ['controller' => 'Outcomes', 'action' => 'saved'],
    ['_method' => 'GET', '_name' => 'demo:outcomes:saved'],
);
$routes->connect(
    '/outcomes/invoice',
    ['controller' => 'Outcomes', 'action' => 'invoice'],
    ['_method' => 'GET', '_name' => 'demo:outcomes:invoice'],
);
$routes->connect(
    '/realtime',
    ['controller' => 'Realtime', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:realtime'],
);
$routes->connect(
    '/realtime/scoped',
    ['controller' => 'Realtime', 'action' => 'scoped'],
    ['_method' => 'GET', '_name' => 'demo:realtime:scoped'],
);
$routes->connect(
    '/realtime/update',
    ['controller' => 'Realtime', 'action' => 'update', 'interactScope' => 'realtime-demo'],
    ['_method' => 'POST', '_name' => 'demo:realtime:update'],
);
$routes->connect(
    '/realtime/subscribe',
    ['controller' => 'Realtime', 'action' => 'subscribe', 'interactScope' => 'realtime-demo'],
    ['_method' => 'POST', '_name' => 'demo:realtime:subscribe'],
);
$routes->connect(
    '/demo/realtime-user/{userId}',
    ['controller' => 'Realtime', 'action' => 'user'],
    ['_method' => 'POST', '_name' => 'demo:realtime-user', 'pass' => ['userId']],
);
$routes->connect(
    '/renderless',
    ['controller' => 'Renderless', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:renderless'],
);
$routes->connect(
    '/composition',
    ['controller' => 'Resources', 'action' => 'composition'],
    ['_method' => 'GET', '_name' => 'demo:composition'],
);
$routes->connect(
    '/search',
    ['controller' => 'Resources', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:search'],
);
$routes->connect(
    '/search/state-only',
    ['controller' => 'Resources', 'action' => 'stateOnly'],
    ['_method' => 'GET', '_name' => 'demo:search:state-only'],
);
$routes->connect(
    '/search/independent',
    ['controller' => 'Resources', 'action' => 'independent'],
    ['_method' => 'GET', '_name' => 'demo:search:independent'],
);
$routes->connect(
    '/search/multiple',
    ['controller' => 'Resources', 'action' => 'multiple'],
    ['_method' => 'GET', '_name' => 'demo:search:multiple'],
);
$routes->connect(
    '/demo/url-state',
    ['controller' => 'Resources', 'action' => 'urlState'],
    ['_method' => 'GET', '_name' => 'demo:url-state'],
);
$routes->connect(
    '/streaming',
    ['controller' => 'Streaming', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:streaming'],
);
$routes->connect(
    '/demo/streaming-probe',
    ['controller' => 'Streaming', 'action' => 'probe'],
    ['_method' => 'GET', '_name' => 'demo:streaming-probe'],
);
$routes->connect(
    '/demo/streaming-session',
    ['controller' => 'Streaming', 'action' => 'session'],
    ['_method' => 'GET', '_name' => 'demo:streaming-session'],
);
$routes->connect(
    '/uploads',
    ['controller' => 'Uploads', 'action' => 'index'],
    ['_method' => 'GET', '_name' => 'demo:uploads'],
);
$routes->connect(
    '/uploads/update',
    ['controller' => 'Uploads', 'action' => 'update', 'interactScope' => 'uploads-demo'],
    ['_method' => 'POST', '_name' => 'demo:uploads:update'],
);
