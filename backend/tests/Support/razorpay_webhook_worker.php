<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$configPath = $argv[1] ?? null;
$outputPath = $argv[2] ?? null;

if (!$configPath || !$outputPath || !is_file($configPath)) {
    fwrite(STDERR, "Usage: php razorpay_webhook_worker.php request.json output.json\n");
    exit(2);
}

$settings = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$app['config']->set('database.default', $settings['database']);
$app['config']->set('payments.razorpay_webhook_secret', $settings['secret']);

DB::purge($settings['database']);
DB::setDefaultConnection($settings['database']);

if (DB::connection()->getDatabaseName() !== 'mewar_properties_concurrency_test') {
    fwrite(STDERR, "Worker refused unexpected database.\n");
    exit(3);
}

// Wait for the parent to release both independent workers together.
$deadline = microtime(true) + 20;
while (!is_file($settings['gate_path'])) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "Timed out waiting for the start gate.\n");
        exit(4);
    }
    usleep(10000);
}

$server = [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_X_RAZORPAY_SIGNATURE' => $settings['signature'],
    'HTTP_X_RAZORPAY_EVENT_ID' => $settings['event_id'],
];

$request = Request::create(
    '/api/webhooks/razorpay',
    'POST',
    [],
    [],
    [],
    $server,
    $settings['raw']
);

$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$body = json_decode($response->getContent() ?: '', true);

$result = [
    'http_status' => $response->getStatusCode(),
    'body' => $body,
];

file_put_contents($outputPath, json_encode($result, JSON_THROW_ON_ERROR));
$kernel->terminate($request, $response);

exit($response->isSuccessful() ? 0 : 1);
