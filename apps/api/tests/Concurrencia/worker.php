<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$job = json_decode((string) file_get_contents($argv[1]), true);

$kernel->bootstrap();
$app->make('db')->connection()->getPdo();
file_put_contents($argv[1] . '.ready', '1');

$limit = microtime(true) + 120;

while (!file_exists($job['go']) && microtime(true) < $limit) {
    usleep(500);
}

$request = Illuminate\Http\Request::create($job['uri'], $job['method'], [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer ' . $job['token'],
    'HTTP_ACCEPT'        => 'application/json',
    'CONTENT_TYPE'       => 'application/json',
], json_encode($job['body'] ?? []));

$response = $kernel->handle($request);

echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)]);
