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

$config = $app['config'];
$config->set('database.default', 'mysql_concurrency_test');
DB::purge('mysql_concurrency_test');
DB::setDefaultConnection('mysql_concurrency_test');

$actualDatabase = DB::connection()->getDatabaseName();
if ($actualDatabase !== 'mewar_properties_concurrency_test') {
    fwrite(STDERR, "Refusing to run: unexpected database {$actualDatabase}\n");
    exit(2);
}

$secret = 'local_webhook_test_secret_123';
$config->set('payments.razorpay_webhook_secret', $secret);

$suffix = bin2hex(random_bytes(8));
$eventId = 'evt_concurrency_' . $suffix;
$orderId = 'order_concurrency_' . $suffix;
$providerPaymentId = 'pay_concurrency_' . $suffix;
$now = now();

$customer = User::create([
    'name' => 'Webhook Concurrency Test',
    'email' => 'webhook-concurrency-' . $suffix . '@mewar.local',
    'password' => 'TemporaryTestPassword123!',
]);

$payment = Payment::create([
    'customer_id' => $customer->id,
    'payment_reference' => 'MWR-CONC-' . strtoupper($suffix),
    'purpose' => 'service_fee',
    'payment_method' => 'razorpay',
    'payment_gateway' => 'razorpay',
    'currency' => 'INR',
    'amount' => '100.00',
    'description' => 'Isolated MySQL webhook concurrency test',
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

$payload = [
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

$raw = json_encode($payload, JSON_THROW_ON_ERROR);
$signature = hash_hmac('sha256', $raw, $secret);

$workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mewar_webhook_' . $suffix;
if (!mkdir($workDir) && !is_dir($workDir)) {
    throw new RuntimeException("Could not create work directory: {$workDir}");
}

$configPath = $workDir . DIRECTORY_SEPARATOR . 'request.json';
$gatePath = $workDir . DIRECTORY_SEPARATOR . 'start.signal';
$output1 = $workDir . DIRECTORY_SEPARATOR . 'worker1.json';
$output2 = $workDir . DIRECTORY_SEPARATOR . 'worker2.json';
$worker = __DIR__ . DIRECTORY_SEPARATOR . 'razorpay_webhook_worker.php';

file_put_contents($configPath, json_encode([
    'database' => 'mysql_concurrency_test',
    'event_id' => $eventId,
    'secret' => $secret,
    'raw' => $raw,
    'signature' => $signature,
    'gate_path' => $gatePath,
], JSON_THROW_ON_ERROR));

$php = PHP_BINARY;
$commandBase = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($configPath);

$processes = [];
foreach ([$output1, $output2] as $outputPath) {
    $command = $commandBase . ' ' . escapeshellarg($outputPath);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2));

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a webhook worker process.');
    }

    fclose($pipes[0]);
    $processes[] = [$process, $pipes, $outputPath];
}

// Both independent PHP processes are waiting at the same gate.
usleep(250000);
file_put_contents($gatePath, 'go');

$workerResults = [];
foreach ($processes as [$process, $pipes, $outputPath]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $result = is_file($outputPath)
        ? json_decode((string) file_get_contents($outputPath), true)
        : null;

    $workerResults[] = [
        'exit_code' => $exitCode,
        'result' => $result,
        'stdout' => trim((string) $stdout),
        'stderr' => trim((string) $stderr),
    ];
}

DB::purge('mysql_concurrency_test');

$event = DB::table('payment_events')
    ->where('provider', 'razorpay')
    ->where('provider_event_id', $eventId)
    ->first();

$storedPayment = Payment::findOrFail($payment->id);
$storedAttempt = PaymentAttempt::where('payment_id', $payment->id)->firstOrFail();

$eventCount = DB::table('payment_events')
    ->where('provider', 'razorpay')
    ->where('provider_event_id', $eventId)
    ->count();

$statuses = array_map(
    fn ($workerResult) => $workerResult['result']['http_status'] ?? null,
    $workerResults
);

$bodyStatuses = array_map(
    fn ($workerResult) => $workerResult['result']['body']['status'] ?? null,
    $workerResults
);

$checks = [
    'exactly_one_event_row' => $eventCount === 1,
    'event_processed' => ($event->processing_status ?? null) === 'processed',
    'event_attempted_once' => (int) ($event->processing_attempts ?? -1) === 1,
    'payment_paid' => $storedPayment->status === 'paid',
    'payment_amount_paid_100' => (string) $storedPayment->amount_paid === '100.00',
    'attempt_captured' => $storedAttempt->status === 'captured',
    'both_http_200' => $statuses === [200, 200],
    'one_processed_one_duplicate' => count(array_filter($bodyStatuses, fn ($s) => $s === 'processed')) === 1
        && count(array_filter($bodyStatuses, fn ($s) => $s === 'duplicate')) === 1,
    'workers_exited_successfully' => count(array_filter($workerResults, fn ($r) => $r['exit_code'] === 0)) === 2,
];

echo "\nRazorpay webhook concurrency test\n";
echo "Database: {$actualDatabase}\n";
echo "Payment ID: {$payment->id}\n";
echo "Attempt ID: {$attempt->id}\n";
echo "Event ID: {$eventId}\n\n";
echo "Worker results:\n";
foreach ($workerResults as $index => $result) {
    echo '  Worker ' . ($index + 1) . ': ' . json_encode($result['result'] ?? $result, JSON_UNESCAPED_SLASHES) . "\n";
}

echo "\nDatabase assertions:\n";
foreach ($checks as $name => $passed) {
    echo '  ' . ($passed ? '[PASS] ' : '[FAIL] ') . $name . "\n";
}

echo "\nStored event status: " . ($event->processing_status ?? 'missing') . "\n";
echo "Stored processing attempts: " . ($event->processing_attempts ?? 'missing') . "\n";
echo "Stored payment status: {$storedPayment->status}\n";
echo "Stored attempt status: {$storedAttempt->status}\n";

$allPassed = !in_array(false, $checks, true);

// Keep the uniquely identified test records for inspection; no cleanup/truncation.
echo "\nTest artifacts retained in the dedicated concurrency database.\n";
echo "Temporary worker files: {$workDir}\n";

exit($allPassed ? 0 : 1);
