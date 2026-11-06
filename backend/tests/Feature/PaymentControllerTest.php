<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\ConnectionException;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private string $razorpaySecret = 'local_checkout_test_secret_123';

    private string $webhookSecret = 'local_webhook_test_secret_123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->customer = User::factory()->create();

        $customerRole = Role::where('name', 'customer')->firstOrFail();

        $this->customer->roles()->attach($customerRole);

        Config::set([
            'payments.currency' => 'INR',
            'payments.service_fee_amount' => 10000,
            'payments.booking_token_amount' => 50000,
            'payments.razorpay_key_id' => 'rzp_test_local',
            'payments.razorpay_key_secret' => $this->razorpaySecret,
            'payments.razorpay_webhook_secret' => $this->webhookSecret,
            'payments.razorpay_base_url' => 'https://api.razorpay.com/v1',
        ]);

        Sanctum::actingAs($this->customer);
    }


    public function test_same_idempotency_key_does_not_create_duplicate_payment(): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response([
                'id' => 'order_idempotency_001',
                'entity' => 'order',
                'amount' => 10000,
                'currency' => 'INR',
            ], 200),
        ]);

        $payload = [
            'purpose' => 'service_fee',
            'payment_method' => 'razorpay',
        ];

        $headers = [
            'Idempotency-Key' => 'payment-request-test-001',
        ];

        $firstResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/payments', $payload);

        $firstResponse->assertCreated()
            ->assertJsonPath('data.razorpay_order_id', 'order_idempotency_001');

        $secondResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/payments', $payload);

        $secondResponse->assertOk()
            ->assertJsonPath('data.razorpay_order_id', 'order_idempotency_001');

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentAttempt::count());

        Http::assertSentCount(1);
    }


    public function test_same_idempotency_key_with_different_payload_returns_conflict(): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response([
                'id' => 'order_idempotency_002',
                'entity' => 'order',
                'amount' => 10000,
                'currency' => 'INR',
            ], 200),
        ]);

        $headers = [
            'Idempotency-Key' => 'payment-request-conflict-001',
        ];

        // First request creates a service fee payment.
        $this->withHeaders($headers)
            ->postJson('/api/v1/payments', [
                'purpose' => 'service_fee',
                'payment_method' => 'razorpay',
            ])
            ->assertCreated();

        // Second request uses the same key but a different purpose.
        $this->withHeaders($headers)
            ->postJson('/api/v1/payments', [
                'purpose' => 'booking_token',
                'payment_method' => 'razorpay',
            ])
            ->assertStatus(409);

        // No duplicate payment or attempt should be created.
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentAttempt::count());

        // Razorpay should receive only one order request.
        Http::assertSentCount(1);
    }

    public function test_same_idempotency_key_does_not_create_duplicate_offline_payment(): void
    {
        $headers = [
            'Idempotency-Key' => 'offline-payment-idempotency-001',
        ];

        $payload = [
            'purpose' => 'service_fee',
            'payment_method' => 'offline',
        ];

        // First request creates the offline payment.
        $firstResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/payments', $payload);

        $firstResponse->assertCreated();

        // Second request returns the existing payment.
        $secondResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/payments', $payload);

        $secondResponse->assertOk();

        $this->assertSame(1, Payment::count());
        $this->assertSame(0, PaymentAttempt::count());
    }

    public function test_payment_creation_works_without_idempotency_key(): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::sequence()
                ->push([
                    'id' => 'order_without_key_001',
                    'amount' => 10000,
                    'currency' => 'INR',
                ], 200)
                ->push([
                    'id' => 'order_without_key_002',
                    'amount' => 10000,
                    'currency' => 'INR',
                ], 200),
        ]);

        $payload = [
            'purpose' => 'service_fee',
            'payment_method' => 'razorpay',
        ];

        $this->postJson('/api/v1/payments', $payload)
            ->assertCreated();

        $this->postJson('/api/v1/payments', $payload)
            ->assertCreated();

        $this->assertSame(2, Payment::count());
        $this->assertSame(2, PaymentAttempt::count());

        Http::assertSentCount(2);
    }

    public function test_customer_can_create_razorpay_order(): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response([
                'id' => 'order_test_feature_001',
                'entity' => 'order',
                'amount' => 10000,
                'currency' => 'INR',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/payments', [
            'purpose' => 'service_fee',
            'payment_method' => 'razorpay',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.razorpay_order_id', 'order_test_feature_001')
            ->assertJsonPath('data.razorpay_key_id', 'rzp_test_local')
            ->assertJsonPath('data.status', 'pending');

        $payment = Payment::firstOrFail();

        $this->assertSame('100.00', $payment->amount);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('order_test_feature_001', $payment->provider_order_id);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'provider_order_id' => 'order_test_feature_001',
            'status' => 'created',
            'amount' => '100.00',
        ]);

        Http::assertSent(function (ClientRequest $request) {
            return $request->url() === 'https://api.razorpay.com/v1/orders'
                && $request['amount'] === 10000
                && $request['currency'] === 'INR'
                && $request->hasHeader(
                    'Authorization',
                    'Basic ' . base64_encode(
                        'rzp_test_local:' . $this->razorpaySecret
                    )
                );
        });
    }

    public function test_failed_razorpay_order_creation_marks_payment_failed(): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response([
                'error' => [
                    'code' => 'BAD_REQUEST_ERROR',
                    'description' => 'Invalid request',
                ],
            ], 400),
        ]);

        $response = $this->postJson('/api/v1/payments', [
            'purpose' => 'service_fee',
            'payment_method' => 'razorpay',
        ]);

        $response->assertStatus(502)
            ->assertJson([
                'message' => 'Could not create the online payment order.',
            ]);

        $payment = Payment::firstOrFail();

        $this->assertSame('failed', $payment->status);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'status' => 'failed',
            'failure_code' => 'order_creation_failed',
        ]);
    }

    public function test_razorpay_connection_timeout_keeps_payment_pending_and_attempt_unknown(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out.');
        });

        $response = $this->postJson('/api/v1/payments', [
            'purpose' => 'service_fee',
            'payment_method' => 'razorpay',
        ]);

        $response->assertStatus(502)
            ->assertJson([
                'message' => 'Payment order status could not be confirmed. Please contact support before retrying.',
            ]);

        $payment = Payment::firstOrFail();

        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->provider_order_id);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'status' => 'unknown',
            'failure_code' => 'gateway_response_unknown',
        ]);
    }

    public function test_razorpay_connection_timeout_recovers_existing_order_by_receipt(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'POST') {
                throw new ConnectionException('Connection timed out.');
            }

            parse_str(
                (string) parse_url($request->url(), PHP_URL_QUERY),
                $query
            );

            return Http::response([
                'entity' => 'collection',
                'count' => 1,
                'items' => [
                    [
                        'id' => 'order_recovered_001',
                        'entity' => 'order',
                        'amount' => 10000,
                        'amount_paid' => 0,
                        'amount_due' => 10000,
                        'currency' => 'INR',
                        'receipt' => $query['receipt'] ?? '',
                        'status' => 'created',
                        'attempts' => 0,
                    ],
                ],
            ], 200);
        });

        $response = $this->postJson('/api/v1/payments', [
            'purpose' => 'service_fee',
            'payment_method' => 'razorpay',
        ]);

        $response->assertCreated()
            ->assertJsonPath(
                'data.razorpay_order_id',
                'order_recovered_001'
            )
            ->assertJsonPath('data.status', 'pending');

        $payment = Payment::firstOrFail();

        $this->assertSame(
            'order_recovered_001',
            $payment->provider_order_id
        );

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'provider_order_id' => 'order_recovered_001',
            'status' => 'created',
            'failure_code' => null,
        ]);

    }

    public function test_customer_can_verify_valid_checkout_signature(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_checkout_001'
        );

        $providerPaymentId = 'pay_test_checkout_001';

        $signature = hash_hmac(
            'sha256',
            'order_test_checkout_001|' . $providerPaymentId,
            $this->razorpaySecret
        );

        $response = $this->postJson(
            "/api/v1/payments/{$payment->id}/verify-checkout",
            [
                'razorpay_order_id' => 'order_test_checkout_001',
                'razorpay_payment_id' => $providerPaymentId,
                'razorpay_signature' => $signature,
            ]
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'amount_paid' => '0.00',
        ]);
    }

    public function test_invalid_checkout_signature_is_rejected(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_checkout_002'
        );

        $response = $this->postJson(
            "/api/v1/payments/{$payment->id}/verify-checkout",
            [
                'razorpay_order_id' => 'order_test_checkout_002',
                'razorpay_payment_id' => 'pay_test_invalid',
                'razorpay_signature' => 'invalid_signature',
            ]
        );

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Invalid payment signature.',
            ]);
    }

    public function test_customer_cannot_verify_another_customers_payment(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_checkout_003'
        );

        $anotherCustomer = User::factory()->create();

        Sanctum::actingAs($anotherCustomer);

        $response = $this->postJson(
            "/api/v1/payments/{$payment->id}/verify-checkout",
            [
                'razorpay_order_id' => 'order_test_checkout_003',
                'razorpay_payment_id' => 'pay_test_checkout_003',
                'razorpay_signature' => 'any_signature',
            ]
        );

        $response->assertForbidden();
    }

    public function test_valid_capture_webhook_marks_payment_as_paid(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_webhook_001'
        );

        $payload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_webhook_001',
                        'order_id' => 'order_test_webhook_001',
                        'amount' => 10000,
                        'currency' => 'INR',
                    ],
                ],
            ],
        ];

        $response = $this->sendWebhook(
            $payload,
            'evt_test_webhook_001'
        );

        $response->assertOk()
            ->assertJson([
                'status' => 'processed',
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'amount_paid' => '100.00',
            'provider_payment_id' => 'pay_test_webhook_001',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'status' => 'captured',
            'provider_payment_id' => 'pay_test_webhook_001',
        ]);

        $this->assertDatabaseHas('payment_events', [
            'provider' => 'razorpay',
            'provider_event_id' => 'evt_test_webhook_001',
            'processing_status' => 'processed',
        ]);
    }

    public function test_duplicate_webhook_event_is_not_processed_again(): void
    {
        $this->createRazorpayPayment('order_test_webhook_002');

        $payload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_webhook_002',
                        'order_id' => 'order_test_webhook_002',
                        'amount' => 10000,
                        'currency' => 'INR',
                    ],
                ],
            ],
        ];

        $this->sendWebhook($payload, 'evt_test_duplicate_001')
            ->assertOk();

        $this->sendWebhook($payload, 'evt_test_duplicate_001')
            ->assertOk()
            ->assertJson([
                'status' => 'duplicate',
            ]);

        $this->assertSame(
            1,
            PaymentEvent::where(
                'provider_event_id',
                'evt_test_duplicate_001'
            )->count()
        );
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $payload = [
            'event' => 'payment.captured',
            'payload' => [],
        ];

        $raw = json_encode($payload);

        $response = $this->call(
            'POST',
            '/api/webhooks/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => 'invalid_signature',
                'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_invalid_signature',
            ],
            $raw
        );

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Invalid webhook signature.',
            ]);
    }

    public function test_admin_can_confirm_offline_payment(): void
    {
        $payment = $this->createOfflinePayment();

        $admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);

        $response = $this->postJson(
            "/api/v1/payments/{$payment->id}/confirm-offline",
            [
                'amount_received' => 100,
                'notes' => 'Cash received and verified.',
            ]
        );

        $response->assertOk()
            ->assertJson([
                'message' => 'Offline payment confirmed.',
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'amount_paid' => '100.00',
            'recorded_by' => $admin->id,
            'internal_notes' => 'Cash received and verified.',
        ]);
    }

    public function test_customer_cannot_confirm_offline_payment(): void
    {
        $payment = $this->createOfflinePayment();

        $response = $this->postJson(
            "/api/v1/payments/{$payment->id}/confirm-offline",
            [
                'amount_received' => 100,
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'amount_paid' => '0.00',
        ]);
    }

    public function test_admin_cannot_confirm_offline_payment_with_wrong_amount(): void
    {
        $payment = $this->createOfflinePayment();

        $admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);

        $response = $this->postJson(
            "/api/v1/payments/{$payment->id}/confirm-offline",
            [
                'amount_received' => 90,
            ]
        );

        $response->assertStatus(422);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'amount_paid' => '0.00',
        ]);
    }
    public function test_admin_can_confirm_offline_payment_with_decimal_amount(): void
    {
        $payment = $this->createOfflinePayment();

        $admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/payments/{$payment->id}/confirm-offline", [
            'amount_received' => '100.00',
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'amount_paid' => '100.00',
        ]);
    }

    public function test_admin_cannot_confirm_offline_payment_with_one_paise_difference(): void
    {
        $payment = $this->createOfflinePayment();

        $admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/payments/{$payment->id}/confirm-offline", [
            'amount_received' => '100.01',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'amount_paid' => '0.00',
        ]);
    }

    public function test_admin_cannot_confirm_offline_payment_with_more_than_two_decimal_places(): void
    {
        $payment = $this->createOfflinePayment();

        $admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/payments/{$payment->id}/confirm-offline", [
            'amount_received' => '100.001',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'amount_paid' => '0.00',
        ]);
    }
    private function createOfflinePayment(): Payment
    {
        return Payment::create([
            'customer_id' => $this->customer->id,
            'payment_reference' => 'MWR-' . strtoupper(uniqid()),
            'purpose' => 'service_fee',
            'payment_method' => 'offline',
            'payment_gateway' => null,
            'currency' => 'INR',
            'amount' => '100.00',
            'description' => 'Offline payment feature test',
        ]);
    }

    public function test_failed_payment_webhook_marks_payment_as_failed(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_failed_001'
        );

        $payload = [
            'event' => 'payment.failed',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_failed_001',
                        'order_id' => 'order_test_failed_001',
                        'amount' => 10000,
                        'currency' => 'INR',
                        'error_code' => 'BAD_REQUEST_ERROR',
                        'error_description' => 'Payment declined.',
                    ],
                ],
            ],
        ];

        $response = $this->sendWebhook(
            $payload,
            'evt_test_failed_001'
        );

        $response->assertOk()
            ->assertJson(['status' => 'processed']);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
            'amount_paid' => '0.00',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'status' => 'failed',
            'failure_code' => 'BAD_REQUEST_ERROR',
            'failure_message' => 'Payment declined.',
        ]);
    }

    public function test_webhook_with_amount_mismatch_is_rejected(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_mismatch_001'
        );

        $payload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_mismatch_001',
                        'order_id' => 'order_test_mismatch_001',
                        'amount' => 9000,
                        'currency' => 'INR',
                    ],
                ],
            ],
        ];

        $response = $this->sendWebhook(
            $payload,
            'evt_test_mismatch_001'
        );

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Webhook did not match a valid payment.',
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'amount_paid' => '0.00',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'status' => 'created',
        ]);
    }

    public function test_order_paid_webhook_marks_payment_as_paid(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_paid_001'
        );

        $payload = [
            'event' => 'order.paid',
            'payload' => [
                'order' => [
                    'entity' => [
                        'id' => 'order_test_paid_001',
                        'amount' => 10000,
                        'amount_paid' => 10000,
                        'currency' => 'INR',
                    ],
                ],
            ],
        ];

        $response = $this->sendWebhook(
            $payload,
            'evt_test_order_paid_001'
        );

        $response->assertOk()
            ->assertJson(['status' => 'processed']);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'amount_paid' => '100.00',
            'provider_order_id' => 'order_test_paid_001',
            'provider_payment_id' => null,
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'status' => 'captured',
            'provider_order_id' => 'order_test_paid_001',
            'provider_payment_id' => null,
        ]);
    }
    public function test_razorpay_order_creation_fails_when_credentials_are_missing(): void
    {
        Config::set('payments.razorpay_key_id', null);
        Config::set('payments.razorpay_key_secret', null);

        $response = $this->postJson('/api/v1/payments', [
            'purpose' => 'service_fee',
            'payment_method' => 'razorpay',
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'Online payments are not configured.',
            ]);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_attempts', 0);

        Http::assertNothingSent();
    }

    public function test_checkout_verification_fails_when_secret_is_missing(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_missing_secret_001'
        );

        Config::set('payments.razorpay_key_secret', null);

        $response = $this->postJson(
            "/api/v1/payments/{$payment->id}/verify-checkout",
            [
                'razorpay_order_id' => 'order_test_missing_secret_001',
                'razorpay_payment_id' => 'pay_test_missing_secret_001',
                'razorpay_signature' => 'some_signature',
            ]
        );

        $response->assertStatus(503)
            ->assertJson([
                'message' => 'Payment verification is not configured.',
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'amount_paid' => '0.00',
        ]);
    }

    public function test_customer_can_view_own_payment(): void
    {
        $payment = $this->createPaymentFor($this->customer);

        $this->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonFragment([
                'payment_reference' => $payment->payment_reference,
            ]);

        $this->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id);
    }

    public function test_customer_cannot_view_another_customers_payment(): void
    {
        $anotherCustomer = User::factory()->create();

        $payment = $this->createPaymentFor($anotherCustomer);

        $this->getJson("/api/v1/payments/{$payment->id}")
            ->assertForbidden();
    }

    public function test_admin_can_view_all_payments(): void
    {
        $anotherCustomer = User::factory()->create();

        $firstPayment = $this->createPaymentFor($this->customer);
        $secondPayment = $this->createPaymentFor($anotherCustomer);

        $admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin->roles()->attach($adminRole);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonFragment([
                'payment_reference' => $firstPayment->payment_reference,
            ])
            ->assertJsonFragment([
                'payment_reference' => $secondPayment->payment_reference,
            ]);
    }

    public function test_property_owner_can_view_payment_for_own_property(): void
    {
        $owner = User::factory()->create();

        $ownerRole = Role::where('name', 'owner')->firstOrFail();
        $owner->roles()->attach($ownerRole);

        $property = $this->createTestProperty($owner);

        $payment = $this->createPaymentFor(
            $this->customer,
            $property
        );

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id);
    }

    public function test_property_owner_cannot_view_payment_for_another_owners_property(): void
    {
        $owner = User::factory()->create();
        $anotherOwner = User::factory()->create();

        $ownerRole = Role::where('name', 'owner')->firstOrFail();

        $owner->roles()->attach($ownerRole);
        $anotherOwner->roles()->attach($ownerRole);

        $property = $this->createTestProperty($anotherOwner);

        $payment = $this->createPaymentFor(
            $this->customer,
            $property
        );

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/payments/{$payment->id}")
            ->assertForbidden();
    }

    /**
     * Create a payment for a test customer.
     */
    private function createPaymentFor(
        User $customer,
        ?\App\Models\Property $property = null
    ): Payment {
        return Payment::create([
            'customer_id' => $customer->id,
            'property_id' => $property?->id,
            'payment_reference' => 'MWR-' . strtoupper(uniqid()),
            'purpose' => $property ? 'booking_token' : 'service_fee',
            'payment_method' => 'offline',
            'payment_gateway' => null,
            'currency' => 'INR',
            'amount' => '100.00',
            'description' => 'Payment access feature test',
        ]);
    }

    /**
     * Create a valid test property.
     */
    private function createTestProperty(User $owner): \App\Models\Property
    {
        return \App\Models\Property::create([
            'owner_id' => $owner->id,
            'title' => 'Test Property',
            'slug' => 'test-property-' . uniqid(),
            'property_type' => 'apartment',
            'listing_type' => 'sale',
            'price' => '5750000.00',
            'city' => 'Udaipur',
            'state' => 'Rajasthan',
            'country' => 'India',
            'approval_status' => 'approved',
            'publication_status' => 'published',
            'approved_at' => now(),
            'published_at' => now(),
        ]);
    }

    public function test_paid_payment_rejects_later_webhook_with_wrong_amount(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_paid_mismatch_001'
        );

        // First, capture the payment successfully.
        $capturePayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_paid_mismatch_001',
                        'order_id' => 'order_test_paid_mismatch_001',
                        'amount' => 10000,
                        'currency' => 'INR',
                    ],
                ],
            ],
        ];

        $this->sendWebhook(
            $capturePayload,
            'evt_test_paid_capture_001'
        )->assertOk();

        // Now send another event with an incorrect amount.
        $invalidPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_paid_mismatch_001',
                        'order_id' => 'order_test_paid_mismatch_001',
                        'amount' => 9000,
                        'currency' => 'INR',
                    ],
                ],
            ],
        ];

        $this->sendWebhook(
            $invalidPayload,
            'evt_test_paid_mismatch_001'
        )->assertStatus(422);

        // The original successful payment must remain paid.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'amount_paid' => '100.00',
            'provider_payment_id' => 'pay_test_paid_mismatch_001',
        ]);

        // The invalid event must not be marked as processed.
        $this->assertDatabaseHas('payment_events', [
            'provider_event_id' => 'evt_test_paid_mismatch_001',
            'processing_status' => 'failed',
            'processing_error' => 'Amount or currency mismatch.',
        ]);
    }

    public function test_late_failed_webhook_does_not_downgrade_paid_payment(): void
    {
        $payment = $this->createRazorpayPayment(
            'order_test_late_failure_001'
        );

        // First, successfully capture the payment.
        $capturePayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_late_failure_001',
                        'order_id' => 'order_test_late_failure_001',
                        'amount' => 10000,
                        'currency' => 'INR',
                    ],
                ],
            ],
        ];

        $this->sendWebhook(
            $capturePayload,
            'evt_test_late_capture_001'
        )->assertOk();

        // Then receive a late failure event for the same order.
        $failedPayload = [
            'event' => 'payment.failed',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_late_failure_001',
                        'order_id' => 'order_test_late_failure_001',
                        'amount' => 10000,
                        'currency' => 'INR',
                        'error_code' => 'PAYMENT_FAILED',
                        'error_description' => 'Late failure notification.',
                    ],
                ],
            ],
        ];

        $this->sendWebhook(
            $failedPayload,
            'evt_test_late_failure_001'
        )->assertOk()
            ->assertJson(['status' => 'processed']);

        // The payment must remain successful.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'amount_paid' => '100.00',
            'provider_payment_id' => 'pay_test_late_failure_001',
        ]);

        // The captured attempt must remain unchanged.
        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'status' => 'captured',
            'provider_payment_id' => 'pay_test_late_failure_001',
            'failure_code' => null,
        ]);
    }
    public function test_failed_webhook_can_be_retried_with_same_event_id(): void
    {
        $orderId = 'order_test_retry_001';
        $eventId = 'evt_test_retry_001';

        $payload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_retry_001',
                        'order_id' => $orderId,
                        'amount' => 10000,
                        'currency' => 'INR',
                    ],
                ],
            ],
        ];

        // First delivery: payment does not exist yet.
        $this->sendWebhook($payload, $eventId)
            ->assertStatus(422);

        $this->assertDatabaseHas('payment_events', [
            'provider' => 'razorpay',
            'provider_event_id' => $eventId,
            'processing_status' => 'failed',
            'processing_attempts' => 1,
        ]);

        // Simulate the payment becoming available before Razorpay retries.
        $payment = $this->createRazorpayPayment($orderId);

        // Second delivery: same event ID and same payload.
        $this->sendWebhook($payload, $eventId)
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        // The existing event must be reused, not duplicated.
        $this->assertSame(
            1,
            DB::table('payment_events')
                ->where('provider', 'razorpay')
                ->where('provider_event_id', $eventId)
                ->count()
        );

        $this->assertDatabaseHas('payment_events', [
            'provider' => 'razorpay',
            'provider_event_id' => $eventId,
            'payment_id' => $payment->id,
            'processing_status' => 'processed',
            'processing_attempts' => 2,
            'processing_error' => null,
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'amount_paid' => '100.00',
            'provider_payment_id' => 'pay_test_retry_001',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'status' => 'captured',
            'provider_payment_id' => 'pay_test_retry_001',
        ]);
    }

    public function test_payment_creation_is_rate_limited_after_ten_requests(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/v1/payments', [])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/payments', [])
            ->assertStatus(429);
    }

    public function test_razorpay_webhook_is_rate_limited_after_120_requests(): void
    {
        $payload = [
            'event' => 'payment.authorized',
            'payload' => [],
        ];

        $raw = json_encode($payload);

        $signature = hash_hmac(
            'sha256',
            $raw,
            $this->webhookSecret
        );

        for ($i = 1; $i <= 120; $i++) {
            $this->call(
                'POST',
                '/api/webhooks/razorpay',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'REMOTE_ADDR' => '192.0.2.120',
                    'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
                    'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_rate_limit_' . $i,
                ],
                $raw
            )->assertOk()
                ->assertJson(['status' => 'ignored']);
        }

        $this->call(
            'POST',
            '/api/webhooks/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => '192.0.2.120',
                'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
                'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_rate_limit_121',
            ],
            $raw
        )->assertStatus(429);
    }


    public function test_customer_can_retry_failed_razorpay_payment(): void
    {
        $payment = $this->createRazorpayPayment('order_retry_old');

        PaymentAttempt::where('payment_id', $payment->id)->firstOrFail()
            ->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
            ])->save();

        $payment->forceFill(['status' => 'failed'])->save();

        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response([
                'id' => 'order_retry_new',
                'amount' => 10000,
                'currency' => 'INR',
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/payments/{$payment->id}/retry");

        $response->assertCreated()
            ->assertJsonPath('data.payment_id', $payment->id)
            ->assertJsonPath('data.attempt_number', 2)
            ->assertJsonPath('data.razorpay_order_id', 'order_retry_new')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'provider_order_id' => 'order_retry_new',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_number' => 2,
            'provider_order_id' => 'order_retry_new',
            'status' => 'created',
        ]);
    }



    public function test_pending_payment_cannot_be_retried(): void
    {
        $payment = $this->createRazorpayPayment('order_pending_retry');

        $this->postJson("/api/v1/payments/{$payment->id}/retry")
            ->assertUnprocessable();

        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_paid_payment_cannot_be_retried(): void
    {
        $payment = $this->createRazorpayPayment('order_paid_retry');

        $payment->forceFill([
            'status' => 'paid',
            'amount_paid' => '100.00',
        ])->save();

        $this->postJson("/api/v1/payments/{$payment->id}/retry")
            ->assertUnprocessable();

        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_offline_payment_cannot_be_retried(): void
    {
        $payment = $this->createOfflinePayment();

        $this->postJson("/api/v1/payments/{$payment->id}/retry")
            ->assertUnprocessable();
    }

    public function test_unknown_attempt_cannot_be_retried(): void
    {
        $payment = $this->createRazorpayPayment('order_unknown_retry');

        PaymentAttempt::where('payment_id', $payment->id)->firstOrFail()
            ->forceFill([
                'status' => 'unknown',
            ])->save();

        $payment->forceFill(['status' => 'failed'])->save();

        $this->postJson("/api/v1/payments/{$payment->id}/retry")
            ->assertUnprocessable();

        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_customer_cannot_retry_another_customers_payment(): void
    {
        $payment = $this->createRazorpayPayment('order_other_customer_retry');

        $anotherCustomer = User::factory()->create();

        Sanctum::actingAs($anotherCustomer);

        $this->postJson("/api/v1/payments/{$payment->id}/retry")
            ->assertForbidden();
    }

    public function test_old_attempt_failure_webhook_does_not_fail_new_retry(): void
    {
        $payment = $this->createRazorpayPayment('order_old_attempt');

        $firstAttempt = PaymentAttempt::where('payment_id', $payment->id)
            ->firstOrFail();

        $firstAttempt->forceFill([
            'status' => 'failed',
            'completed_at' => now(),
        ])->save();

        $payment->forceFill(['status' => 'failed'])->save();

        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response([
                'id' => 'order_new_attempt',
                'amount' => 10000,
                'currency' => 'INR',
            ], 200),
        ]);

        $this->postJson("/api/v1/payments/{$payment->id}/retry")
            ->assertCreated();

        // A delayed failure notification arrives for attempt 1.
        $payload = [
            'event' => 'payment.failed',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_old_attempt',
                        'order_id' => 'order_old_attempt',
                        'amount' => 10000,
                        'currency' => 'INR',
                        'error_code' => 'PAYMENT_FAILED',
                        'error_description' => 'Old attempt failed.',
                    ],
                ],
            ],
        ];

        $this->sendWebhook($payload, 'evt_old_attempt_failure')
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        // New attempt must remain active.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
            'provider_order_id' => 'order_new_attempt',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $firstAttempt->id,
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_number' => 2,
            'provider_order_id' => 'order_new_attempt',
            'status' => 'created',
        ]);
    }

public function test_admin_can_reconcile_captured_razorpay_payment(): void
{
    $orderId = 'order_test_reconcile_success';
    $payment = $this->createRazorpayPayment($orderId);

    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'admin')->firstOrFail();
    $admin->roles()->attach($adminRole);

    Http::fake([
        "api.razorpay.com/v1/orders/{$orderId}" => Http::response([
            'id' => $orderId,
            'receipt' => $payment->payment_reference,
            'amount' => 10000,
            'currency' => 'INR',
        ], 200),

        "api.razorpay.com/v1/orders/{$orderId}/payments" => Http::response([
            'items' => [[
                'id' => 'pay_test_reconcile_success',
                'order_id' => $orderId,
                'amount' => 10000,
                'currency' => 'INR',
                'status' => 'captured',
            ]],
        ], 200),
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/payments/{$payment->id}/reconcile")
        ->assertOk()
        ->assertJsonPath('message', 'Payment successfully reconciled and marked as paid.')
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.razorpay_payment_id', 'pay_test_reconcile_success');

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'paid',
        'amount_paid' => '100.00',
        'provider_order_id' => $orderId,
        'provider_payment_id' => 'pay_test_reconcile_success',
    ]);

    $this->assertDatabaseHas('payment_attempts', [
        'payment_id' => $payment->id,
        'status' => 'captured',
        'provider_payment_id' => 'pay_test_reconcile_success',
    ]);
}

public function test_customer_cannot_reconcile_razorpay_payment(): void
{
    $payment = $this->createRazorpayPayment(
        'order_test_reconcile_customer'
    );

    $this->postJson("/api/v1/payments/{$payment->id}/reconcile")
        ->assertForbidden();

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'pending',
        'amount_paid' => '0.00',
    ]);
}

public function test_reconciliation_keeps_payment_pending_when_no_capture_exists(): void
{
    $orderId = 'order_test_reconcile_pending';
    $payment = $this->createRazorpayPayment($orderId);

    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'admin')->firstOrFail();
    $admin->roles()->attach($adminRole);

    Http::fake([
        "api.razorpay.com/v1/orders/{$orderId}" => Http::response([
            'id' => $orderId,
            'receipt' => $payment->payment_reference,
            'amount' => 10000,
            'currency' => 'INR',
        ], 200),

        "api.razorpay.com/v1/orders/{$orderId}/payments" => Http::response([
            'items' => [],
        ], 200),
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/payments/{$payment->id}/reconcile")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'pending',
        'amount_paid' => '0.00',
        'provider_order_id' => $orderId,
    ]);

    $this->assertDatabaseHas('payment_attempts', [
        'payment_id' => $payment->id,
        'status' => 'created',
    ]);
}

public function test_reconciliation_keeps_payment_pending_when_provider_attempt_failed(): void
{
    $orderId = 'order_test_reconcile_failed_attempt';
    $payment = $this->createRazorpayPayment($orderId);

    $attempt = PaymentAttempt::where('payment_id', $payment->id)
        ->firstOrFail();

    $attempt->forceFill([
        'status' => 'failed',
        'failure_code' => 'PAYMENT_FAILED',
        'completed_at' => now(),
    ])->save();

    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'admin')->firstOrFail();
    $admin->roles()->attach($adminRole);

    Http::fake([
        "api.razorpay.com/v1/orders/{$orderId}" => Http::response([
            'id' => $orderId,
            'receipt' => $payment->payment_reference,
            'amount' => 10000,
            'currency' => 'INR',
        ], 200),

        "api.razorpay.com/v1/orders/{$orderId}/payments" => Http::response([
            'items' => [[
                'id' => 'pay_test_failed_attempt',
                'order_id' => $orderId,
                'amount' => 10000,
                'currency' => 'INR',
                'status' => 'failed',
            ]],
        ], 200),
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/payments/{$payment->id}/reconcile")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'pending',
        'amount_paid' => '0.00',
    ]);

    $this->assertDatabaseHas('payment_attempts', [
        'id' => $attempt->id,
        'status' => 'failed',
    ]);
}

public function test_reconciliation_rejects_captured_payment_with_wrong_amount(): void
{
    $orderId = 'order_test_reconcile_wrong_amount';
    $payment = $this->createRazorpayPayment($orderId);

    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'admin')->firstOrFail();
    $admin->roles()->attach($adminRole);

    Http::fake([
        "api.razorpay.com/v1/orders/{$orderId}" => Http::response([
            'id' => $orderId,
            'receipt' => $payment->payment_reference,
            'amount' => 10000,
            'currency' => 'INR',
        ], 200),

        "api.razorpay.com/v1/orders/{$orderId}/payments" => Http::response([
            'items' => [[
                'id' => 'pay_test_wrong_amount',
                'order_id' => $orderId,
                'amount' => 9000,
                'currency' => 'INR',
                'status' => 'captured',
            ]],
        ], 200),
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/payments/{$payment->id}/reconcile")
        ->assertStatus(422)
        ->assertJson([
            'message' => 'Captured Razorpay payment does not match the expected amount or currency.',
        ]);

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'pending',
        'amount_paid' => '0.00',
    ]);

    $this->assertDatabaseHas('payment_attempts', [
        'payment_id' => $payment->id,
        'status' => 'created',
    ]);
}

public function test_reconciliation_rejects_multiple_captured_payments(): void
{
    $orderId = 'order_test_reconcile_multiple';
    $payment = $this->createRazorpayPayment($orderId);

    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'admin')->firstOrFail();
    $admin->roles()->attach($adminRole);

    Http::fake([
        "api.razorpay.com/v1/orders/{$orderId}" => Http::response([
            'id' => $orderId,
            'receipt' => $payment->payment_reference,
            'amount' => 10000,
            'currency' => 'INR',
        ], 200),

        "api.razorpay.com/v1/orders/{$orderId}/payments" => Http::response([
            'items' => [
                [
                    'id' => 'pay_test_multiple_001',
                    'order_id' => $orderId,
                    'amount' => 10000,
                    'currency' => 'INR',
                    'status' => 'captured',
                ],
                [
                    'id' => 'pay_test_multiple_002',
                    'order_id' => $orderId,
                    'amount' => 10000,
                    'currency' => 'INR',
                    'status' => 'captured',
                ],
            ],
        ], 200),
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/payments/{$payment->id}/reconcile")
        ->assertStatus(409)
        ->assertJson([
            'message' => 'Multiple captured payments were found for this order. Manual investigation is required.',
        ]);

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'pending',
        'amount_paid' => '0.00',
    ]);

    $this->assertDatabaseHas('payment_attempts', [
        'payment_id' => $payment->id,
        'status' => 'created',
    ]);
}

    private function createRazorpayPayment(string $orderId): Payment
    {
        $payment = Payment::create([
            'customer_id' => $this->customer->id,
            'payment_reference' => 'MWR-' . strtoupper(uniqid()),
            'purpose' => 'service_fee',
            'payment_method' => 'razorpay',
            'payment_gateway' => 'razorpay',
            'currency' => 'INR',
            'amount' => '100.00',
            'description' => 'Automated payment test',
        ]);

        $payment->forceFill([
            'status' => 'pending',
            'amount_paid' => '0.00',
            'provider_order_id' => $orderId,
        ])->save();

        PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'payment_gateway' => 'razorpay',
            'provider_order_id' => $orderId,
            'status' => 'created',
            'amount' => '100.00',
            'currency' => 'INR',
            'initiated_at' => now(),
        ]);

        return $payment;
    }

public function test_admin_cannot_reconcile_refunded_payment(): void
{
    $payment = $this->createRazorpayPayment(
        'order_test_refunded_reconcile_001'
    );

    $payment->forceFill([
        'status' => 'refunded',
        'amount_paid' => '100.00',
        'refunded_at' => now(),
    ])->save();

    $admin = User::factory()->create();
    $adminRole = Role::where('name', 'admin')->firstOrFail();
    $admin->roles()->attach($adminRole);

    Sanctum::actingAs($admin);

    $response = $this->postJson(
        "/api/v1/payments/{$payment->id}/reconcile"
    );

    $response->assertStatus(409)
        ->assertJson([
            'message' => 'Refunded payments cannot be reconciled.',
            'data' => [
                'payment_id' => $payment->id,
                'status' => 'refunded',
            ],
        ]);

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'refunded',
        'amount_paid' => '100.00',
    ]);

    $this->assertNotNull($payment->fresh()->refunded_at);

    $this->assertDatabaseHas('payment_attempts', [
        'payment_id' => $payment->id,
        'status' => 'created',
    ]);
}


public function test_captured_webhook_cannot_change_refunded_payment_to_paid(): void
{
    $payment = $this->createRazorpayPayment(
        'order_test_refunded_capture_001'
    );

    $payment->forceFill([
        'status' => 'refunded',
        'amount_paid' => '100.00',
        'refunded_at' => now(),
    ])->save();

    $payload = [
        'event' => 'payment.captured',
        'payload' => [
            'payment' => [
                'entity' => [
                    'id' => 'pay_test_refunded_capture_001',
                    'order_id' => 'order_test_refunded_capture_001',
                    'amount' => 10000,
                    'currency' => 'INR',
                ],
            ],
        ],
    ];

    $response = $this->sendWebhook(
        $payload,
        'evt_test_refunded_capture_001'
    );

    $response->assertOk()
        ->assertJson([
            'status' => 'ignored',
        ]);

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'refunded',
        'amount_paid' => '100.00',
        'provider_payment_id' => null,
    ]);

    $this->assertDatabaseHas('payment_attempts', [
        'payment_id' => $payment->id,
        'status' => 'created',
        'provider_payment_id' => null,
    ]);

    $this->assertDatabaseHas('payment_events', [
        'provider_event_id' => 'evt_test_refunded_capture_001',
        'processing_status' => 'ignored',
        'processing_error' => 'Payment is already refunded.',
    ]);
}


public function test_failed_webhook_cannot_change_refunded_payment_to_failed(): void
{
    $payment = $this->createRazorpayPayment(
        'order_test_refunded_failure_001'
    );

    $payment->forceFill([
        'status' => 'refunded',
        'amount_paid' => '100.00',
        'refunded_at' => now(),
    ])->save();

    $payload = [
        'event' => 'payment.failed',
        'payload' => [
            'payment' => [
                'entity' => [
                    'id' => 'pay_test_refunded_failure_001',
                    'order_id' => 'order_test_refunded_failure_001',
                    'amount' => 10000,
                    'currency' => 'INR',
                    'error_code' => 'PAYMENT_FAILED',
                    'error_description' => 'Payment failed notification.',
                ],
            ],
        ],
    ];

    $response = $this->sendWebhook(
        $payload,
        'evt_test_refunded_failure_001'
    );

    $response->assertOk()
        ->assertJson([
            'status' => 'ignored',
        ]);

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'refunded',
        'amount_paid' => '100.00',
    ]);

    $this->assertDatabaseHas('payment_attempts', [
        'payment_id' => $payment->id,
        'status' => 'created',
        'failure_code' => null,
    ]);

    $this->assertDatabaseHas('payment_events', [
        'provider_event_id' => 'evt_test_refunded_failure_001',
        'processing_status' => 'ignored',
        'processing_error' => 'Payment is already refunded.',
    ]);
}

    private function sendWebhook(
        array $payload,
        string $eventId
    ) {
        $raw = json_encode($payload);

        $signature = hash_hmac(
            'sha256',
            $raw,
            $this->webhookSecret
        );

        return $this->call(
            'POST',
            '/api/webhooks/razorpay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
                'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
            ],
            $raw
        );
    }
}