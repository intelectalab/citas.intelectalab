<?php

$origin = getenv('E2E_WEB_ORIGIN') ?: 'http://localhost:3005';
$public = realpath(__DIR__ . '/../../apps/api/public');

header_register_callback(function () use ($origin) {
    header_remove('Access-Control-Allow-Origin');
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Vary: Origin');
});

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: ' . ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '*'));
    http_response_code(204);

    return true;
}

$path = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($path !== '/' && is_file($public . $path)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $public . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir($public);

require $public . DIRECTORY_SEPARATOR . 'index.php';
