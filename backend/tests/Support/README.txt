# Razorpay webhook concurrency runner

## Files
Copy these two PHP files into:

- `tests/Support/run_razorpay_webhook_concurrency.php`
- `tests/Support/razorpay_webhook_worker.php`

## Safety
- Uses only the Laravel connection named `mysql_concurrency_test`.
- Refuses to run unless the connected database name is exactly `mewar_properties_concurrency_test`.
- Creates uniquely named user/payment/attempt/event rows.
- Does not truncate, delete, migrate, or reset any table.
- Leaves uniquely identified test records in the dedicated concurrency database for inspection.
- Uses a local test webhook secret only. Never replace it with a production secret.

## Run
From the backend project root in CMD:

```cmd
php tests\Support\run_webhook_concurrency.php
```

The runner starts two separate PHP processes and releases them through a shared start gate. Both send the same signed `payment.captured` event ID.

Expected:
- Both HTTP responses are 200.
- One response body reports `processed`.
- The other reports `duplicate`.
- Exactly one event row exists for the event ID.
- `processing_attempts` is 1.
- Payment is `paid`, amount_paid is `100.00`.
- Payment attempt is `captured`.

If your local webhook secret differs from `local_webhook_test_secret_123`, update the `$secret` value in the parent runner and keep it test-only.
