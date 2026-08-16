<?php
declare(strict_types=1);

/**
 * Reusable API dispatch (included by both /api/index.php and public/index.php).
 */

Bootstrap::init();
Auth::startSession();

$router = new Router();
require_once TERRACHAIN_ROOT . '/api/routes.php';
registerApiRoutes($router, '/api/v1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

try {
    $router->dispatch(Request::method(), Request::path());
} catch (Throwable $e) {
    Bootstrap::handle($e);
}