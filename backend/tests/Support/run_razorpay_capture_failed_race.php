<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$app['config']->set('database.default', 'mysql_concurrency_test');
DB::purge('mysql_concurrency_test');
DB::setDefaultConnection('mysql_concurrency_test');

$database = DB::connection()->getDatabaseName();
if ($database !== 'mewar_properties_concurrency_test') {
    fwrite(STDERR, "Refusing to run against unexpected database: {$database}\n");
    exit(2);
}

$secret = 'local_webhook_test_secret_123';
$app['config']->set('payments.razorpay_webhook_secret', $secret);

$suffix = bin2hex(random_bytes(8));
$orderId = 'order_race_' . $suffix;
$providerPaymentId = 'pay_race_' . $suffix;
$capturedEventId = 'evt_capture_' . $suffix;
$failedEventId = 'evt_failed_' . $suffix;
$now = now();

$customer = User::create([
    'name' => 'Webhook Race Test',
    'email' => 'webhook-race-' . $suffix . '@mewar.local',
    'password' => 'TemporaryTestPassword123!',
]);

$payment = Payment::create([
    'customer_id' => $customer->id,
    'payment_reference' => 'MWR-RACE-' . strtoupper($suffix),
    'purpose' => 'service_fee',
    'payment_method' => 'razorpay',
    'payment_gateway' => 'razorpay',
    'currency' => 'INR',
    'amount' => '100.00',
    'description' => 'Isolated capture-vs-failure concurrency test',
]);

$payment->forceFill([
    'status' => 'pending',
    'amount_paid' => '0.00',
    'provider_order_id' => $orderId,
])->save();

$attempt = PaymentAttempt::create([
    'payment_id' => $payment->id,
    'attempt_number' => 1,
    'payment_gateway' => 'razorpay',
    'provider_order_id' => $orderId,
    'status' => 'created',
    'amount' => '100.00',
    'currency' => 'INR',
    'initiated_at' => $now,
]);

$capturedPayload = [
    'event' => 'payment.captured',
    'payload' => [
        'payment' => [
            'entity' => [
                'id' => $providerPaymentId,
                'order_id' => $orderId,
                'amount' => 10000,
                'currency' => 'INR',
                'status' => 'captured',
            ],
        ],
    ],
];

$failedPayload = [
    'event' => 'payment.failed',
    'payload' => [
        'payment' => [
            'entity' => [
                'id' => $providerPaymentId,
                'order_id' => $orderId,
                'amount' => 10000,
                'currency' => 'INR',
                'status' => 'failed',
                'error_code' => 'BAD_REQUEST_ERROR',
                'error_description' => 'Simulated concurrent failure event.',
            ],
        ],
    ],
];

$workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mewar_webhook_race_' . $suffix;
if (!mkdir($workDir) && !is_dir($workDir)) {
    throw new RuntimeException("Could not create work directory: {$workDir}");
}

$gatePath = $workDir . DIRECTORY_SEPARATOR . 'start.signal';
$worker = __DIR__ . DIRECTORY_SEPARATOR . 'razorpay_race_worker.php';

$jobs = [
    [
        'name' => 'captured',
        'event_id' => $capturedEventId,
        'payload' => $capturedPayload,
        'output' => $workDir . DIRECTORY_SEPARATOR . 'captured.json',
    ],
    [
        'name' => 'failed',
        'event_id' => $failedEventId,
        'payload' => $failedPayload,
        'output' => $workDir . DIRECTORY_SEPARATOR . 'failed.json',
    ],
];

$processes = [];
foreach ($jobs as $job) {
    $raw = json_encode($job['payload'], JSON_THROW_ON_ERROR);
    $requestPath = $workDir . DIRECTORY_SEPARATOR . $job['name'] . '_request.json';

    file_put_contents($requestPath, json_encode([
        'database' => 'mysql_concurrency_test',
        'event_id' => $job['event_id'],
        'secret' => $secret,
        'raw' => $raw,
        'signature' => hash_hmac('sha256', $raw, $secret),
        'gate_path' => $gatePath,
    ], JSON_THROW_ON_ERROR));

    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($worker) . ' '
        . escapeshellarg($requestPath) . ' '
        . escapeshellarg($job['output']);

    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2));

    if (!is_resource($process)) {
        throw new RuntimeException("Could not start {$job['name']} worker.");
    }

    fclose($pipes[0]);
    $processes[] = [
        'name' => $job['name'],
        'process' => $process,
        'pipes' => $pipes,
        'output' => $job['output'],
    ];
}

// Release both independent PHP processes together.
usleep(250000);
file_put_contents($gatePath, 'go');

$workerResults = [];
foreach ($processes as $entry) {
    $stdout = stream_get_contents($entry['pipes'][1]);
    $stderr = stream_get_contents($entry['pipes'][2]);
    fclose($entry['pipes'][1]);
    fclose($entry['pipes'][2]);
    $exitCode = proc_close($entry['process']);

    $result = is_file($entry['output'])
        ? json_decode((string) file_get_contents($entry['output']), true)
        : null;

    $workerResults[$entry['name']] = [
        'exit_code' => $exitCode,
        'result' => $result,
        'stdout' => trim((string) $stdout),
        'stderr' => trim((string) $stderr),
    ];
}

DB::purge('mysql_concurrency_test');

$storedPayment = Payment::findOrFail($payment->id);
$storedAttempt = PaymentAttempt::where('payment_id', $payment->id)->firstOrFail();

$capturedEvent = DB::table('payment_events')
    ->where('provider', 'razorpay')
    ->where('provider_event_id', $capturedEventId)
    ->first();

$failedEvent = DB::table('payment_events')
    ->where('provider', 'razorpay')
    ->where('provider_event_id', $failedEventId)
    ->first();

$capturedCount = DB::table('payment_events')
    ->where('provider', 'razorpay')
    ->where('provider_event_id', $capturedEventId)
    ->count();

$failedCount = DB::table('payment_events')
    ->where('provider', 'razorpay')
    ->where('provider_event_id', $failedEventId)
    ->count();

$captureHttp = $workerResults['captured']['result']['http_status'] ?? null;
$failureHttp = $workerResults['failed']['result']['http_status'] ?? null;
$captureBody = $workerResults['captured']['result']['body']['status'] ?? null;
$failureBody = $workerResults['failed']['result']['body']['status'] ?? null;

$checks = [
    'captured_worker_http_200' => $captureHttp === 200,
    'failed_worker_http_200' => $failureHttp === 200,
    'capture_event_recorded_once' => $capturedCount === 1,
    'failure_event_recorded_once' => $failedCount === 1,
    'capture_event_processed' => ($capturedEvent->processing_status ?? null) === 'processed',
    'failure_event_processed' => ($failedEvent->processing_status ?? null) === 'processed',
    'payment_final_state_paid' => $storedPayment->status === 'paid',
    'payment_amount_paid_100' => (string) $storedPayment->amount_paid === '100.00',
    'attempt_final_state_captured' => $storedAttempt->status === 'captured',
    'provider_payment_id_preserved' => $storedPayment->provider_payment_id === $providerPaymentId,
    'both_workers_exited_successfully' => $workerResults['captured']['exit_code'] === 0
        && $workerResults['failed']['exit_code'] === 0,
];

echo "\nRazorpay capture-vs-failure concurrency test\n";
echo "Database: {$database}\n";
echo "Payment ID: {$payment->id}\n";
echo "Attempt ID: {$attempt->id}\n";
echo "Captured event ID: {$capturedEventId}\n";
echo "Failed event ID: {$failedEventId}\n\n";

foreach (['captured', 'failed'] as $name) {
    $result = $workerResults[$name];
    echo ucfirst($name) . " worker: "
        . json_encode($result['result'] ?? $result, JSON_UNESCAPED_SLASHES)
        . "\n";
}

echo "\nDatabase assertions:\n";
foreach ($checks as $name => $passed) {
    echo '  ' . ($passed ? '[PASS] ' : '[FAIL] ') . $name . "\n";
}

echo "\nFinal payment status: {$storedPayment->status}\n";
echo "Final amount paid: {$storedPayment->amount_paid}\n";
echo "Final attempt status: {$storedAttempt->status}\n";
echo "Captured event status: " . ($capturedEvent->processing_status ?? 'missing') . "\n";
echo "Failed event status: " . ($failedEvent->processing_status ?? 'missing') . "\n";
echo "\nTest records retained in the dedicated concurrency database.\n";
echo "Temporary worker files: {$workDir}\n";

exit(in_array(false, $checks, true) ? 1 : 0);
