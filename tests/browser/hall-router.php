<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

// Synthetic browser fixture. Never deploy this router or bind it outside loopback.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (! str_contains($path, '..') && preg_match('#^/(hall-management|fonts)/#', $path) && is_file($root.'/public'.$path)) {
    return false;
}
if ($path === '/hall/data') {
    $state = json_decode(file_get_contents($root.'/storage/logs/hall-browser-state.json'), true);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($state['test_mode'] ?? '') === 'fail') {
        http_response_code(503);
        echo '{}';
        exit;
    }
    if (($state['test_mode'] ?? '') === 'forbidden') {
        http_response_code(403);
        echo '{}';
        exit;
    }
    $state['updated_at'] = date(DATE_ATOM);
    echo json_encode($state, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($path === '/hall/unlock') {
    echo '<h1>ورود مدیریت</h1>';
    exit;
}
if ($path !== '/hall') {
    http_response_code(404);
    exit;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance('request', Request::capture());
header('Content-Type: text/html; charset=utf-8');
echo view('tracking.hall-management', ['dataUrl' => '/hall/data', 'refreshSeconds' => 5])->render();
