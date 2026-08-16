<?php
declare(strict_types=1);

/**
 * Public web entry point.
 * Serves the static frontend (HTML/CSS/JS) and proxies /api to the API router.
 */
require_once dirname(__DIR__) . '/app/core/App.php';
require_once dirname(__DIR__) . '/app/core/Bootstrap.php';

Bootstrap::init();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rtrim($uri, '/') ?: '/';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (str_starts_with($uri, '/api')) {
    require_once TERRACHAIN_ROOT . '/api/index-req.php';
    exit;
}

$docRoot = __DIR__;
$map = [
    '/' => '/login.html',
    '/index.html' => '/login.html',
];

$file = $map[$uri] ?? $uri;
$full = $docRoot . $file;

if (is_file($full)) {
    $ext = pathinfo($full, PATHINFO_EXTENSION);
    $mime = match ($ext) {
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };
    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-store');
    readfile($full);
    exit;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<h1>404 — Page not found</h1><p><a href="/">Back to TerraChain</a></p>';