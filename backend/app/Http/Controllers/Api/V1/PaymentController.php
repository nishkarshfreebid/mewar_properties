<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class PaymentController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Payment::query()
            ->with([
                'property:id,title,slug,owner_id',
                'customer:id,name,email',
            ]);

        if ($user->hasRole('admin')) {
            // Admin can see all payments.
        } else {
            $isCustomer = $user->hasRole('customer');
            $isOwner = $user->hasRole('owner');

            abort_unless(
                $isCustomer || $isOwner,
                403,
                'You are not allowed to view payments.'
            );

            $query->where(function ($q) use ($user, $isCustomer, $isOwner) {
                if ($isCustomer) {
                    $q->where('customer_id', $user->id);
                }

                if ($isOwner) {
                    $method = $isCustomer ? 'orWhereHas' : 'whereHas';

                    $q->{$method}('property', function ($propertyQuery) use ($user) {
                        $propertyQuery->where('owner_id', $user->id);
                    });
                }
            });
        }

        return response()->json([
            'data' => $query->latest()->paginate(15),
        ]);
    }


    public function show(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($this->canView($request, $payment), 403);
        return response()->json([
            'data' => $payment->load([
                'property:id,title,slug,owner_id',
                'customer:id,name,email',
                'attempts:id,payment_id,attempt_number,payment_gateway,status,amount,currency,initiated_at,completed_at',
            ])
        ]);
    }


    public function store(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasRole('customer'),
            403,
            'Only customers can initiate payments.'
        );

        $data = $request->validate([
            'purpose' => ['required', Rule::in(['booking_token', 'service_fee'])],
            'payment_method' => ['required', Rule::in(['razorpay', 'offline'])],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey !== null) {
            validator(
                ['idempotency_key' => $idempotencyKey],
                ['idempotency_key' => ['required', 'string', 'min:16', 'max:100']]
            )->validate();
        }

        // Check whether this request was already processed.
        if ($idempotencyKey !== null) {
            $existingPayment = Payment::where('customer_id', $request->user()->id)
                ->where('idempotency_key', $idempotencyKey)
                ->with('attempts')
                ->first();

            if ($existingPayment) {
                $sameRequest =
                    $existingPayment->purpose === $data['purpose'] &&
                    $existingPayment->payment_method === $data['payment_method'] &&
                    (int) $existingPayment->property_id === (int) ($data['property_id'] ?? 0) &&
                    $existingPayment->description === ($data['description'] ?? null);

                if (!$sameRequest) {
                    return response()->json([
                        'message' => 'This Idempotency-Key was already used for a different payment request.',
                    ], 409);
                }

                // Existing offline payment.
                if ($existingPayment->payment_method === 'offline') {
                    return response()->json([
                        'message' => 'Payment request already exists.',
                        'data' => $existingPayment,
                    ], 200);
                }

                // Existing Razorpay order.
                if ($existingPayment->provider_order_id) {
                    return response()->json([
                        'message' => 'Payment order already exists.',
                        'data' => [
                            'payment_id' => $existingPayment->id,
                            'payment_reference' => $existingPayment->payment_reference,
                            'amount' => $existingPayment->amount,
                            'currency' => $existingPayment->currency,
                            'status' => $existingPayment->status,
                            'razorpay_order_id' => $existingPayment->provider_order_id,
                            'razorpay_key_id' => (string) config('payments.razorpay_key_id'),
                        ],
                    ], 200);
                }

                return response()->json([
                    'message' => 'Payment request already exists, but its gateway order is not confirmed. Please contact support.',
                    'payment_reference' => $existingPayment->payment_reference,
                ], 409);
            }
        }

        $property = null;

        if (
            $data['purpose'] === 'booking_token' &&
            empty($data['property_id'])
        ) {
            return response()->json([
                'message' => 'A property is required for a booking token payment.',
            ], 422);
        }

        if (!empty($data['property_id'])) {
            $property = Property::query()
                ->whereKey($data['property_id'])
                ->where('approval_status', 'approved')
                ->where('publication_status', 'published')
                ->firstOrFail();
        }

        $amountPaise = (int) config(
            $data['purpose'] === 'booking_token'
            ? 'payments.booking_token_amount'
            : 'payments.service_fee_amount',
            0
        );

        if ($amountPaise <= 0) {
            return response()->json([
                'message' => 'The selected payment amount is not configured. Contact the administrator.',
            ], 503);
        }

        $currency = strtoupper((string) config('payments.currency', 'INR'));

        $amountRupees = number_format(
            $amountPaise / 100,
            2,
            '.',
            ''
        );

        // Check Razorpay configuration BEFORE creating database records.
        $keyId = '';
        $keySecret = '';

        if ($data['payment_method'] === 'razorpay') {
            $keyId = (string) config('payments.razorpay_key_id');
            $keySecret = (string) config('payments.razorpay_key_secret');

            if ($keyId === '' || $keySecret === '') {
                return response()->json([
                    'message' => 'Online payments are not configured.',
                ], 503);
            }
        }

        // Create payment and its initial attempt in one transaction.
        try {
            [$payment, $attempt] = DB::transaction(function () use ($request, $property, $data, $currency, $amountRupees, $idempotencyKey) {
                $payment = Payment::create([
                    'customer_id' => $request->user()->id,
                    'property_id' => $property?->id,
                    'payment_reference' => 'MWR-' . Str::upper(Str::random(20)),
                    'purpose' => $data['purpose'],
                    'payment_method' => $data['payment_method'],
                    'payment_gateway' => $data['payment_method'] === 'razorpay'
                        ? 'razorpay'
                        : null,
                    'currency' => $currency,
                    'amount' => $amountRupees,
                    'description' => $data['description'] ?? null,
                ]);

                // Idempotency key is server-controlled.
                if ($idempotencyKey !== null) {
                    $payment->forceFill([
                        'idempotency_key' => $idempotencyKey,
                    ])->save();
                }

                // Initialize server-controlled payment state.
                $payment->forceFill([
                    'status' => 'pending',
                    'amount_paid' => '0.00',
                ])->save();

                $attempt = null;

                // Offline payments do not require a Razorpay attempt.
                if ($data['payment_method'] === 'razorpay') {
                    $attempt = PaymentAttempt::create([
                        'payment_id' => $payment->id,
                        'attempt_number' => 1,
                        'payment_gateway' => 'razorpay',
                        'status' => 'initiated',
                        'amount' => $amountRupees,
                        'currency' => $currency,
                        'initiated_at' => now(),
                    ]);
                }

                return [$payment, $attempt];
            });
        } catch (QueryException $exception) {
            // Handle only duplicate idempotency-key constraint violations.
            if (
                $idempotencyKey === null ||
                !$this->isIdempotencyUniqueConstraintViolation($exception)
            ) {
                throw $exception;
            }

            // Another simultaneous request may have created this payment.
            $existingPayment = Payment::where('customer_id', $request->user()->id)
                ->where('idempotency_key', $idempotencyKey)
                ->with('attempts')
                ->first();

            if (!$existingPayment) {
                throw $exception;
            }

            $sameRequest =
                $existingPayment->purpose === $data['purpose'] &&
                $existingPayment->payment_method === $data['payment_method'] &&
                (int) $existingPayment->property_id === (int) ($data['property_id'] ?? 0) &&
                $existingPayment->description === ($data['description'] ?? null);

            if (!$sameRequest) {
                return response()->json([
                    'message' => 'This Idempotency-Key was already used for a different payment request.',
                ], 409);
            }

            // Existing offline payment.
            if ($existingPayment->payment_method === 'offline') {
                return response()->json([
                    'message' => 'Payment request already exists.',
                    'data' => $existingPayment,
                ], 200);
            }

            // Existing Razorpay order.
            if ($existingPayment->provider_order_id) {
                return response()->json([
                    'message' => 'Payment order already exists.',
                    'data' => [
                        'payment_id' => $existingPayment->id,
                        'payment_reference' => $existingPayment->payment_reference,
                        'amount' => $existingPayment->amount,
                        'currency' => $existingPayment->currency,
                        'status' => $existingPayment->status,
                        'razorpay_order_id' => $existingPayment->provider_order_id,
                        'razorpay_key_id' => (string) config('payments.razorpay_key_id'),
                    ],
                ], 200);
            }

            return response()->json([
                'message' => 'Payment request already exists, but its gateway order is not confirmed. Please contact support.',
                'payment_reference' => $existingPayment->payment_reference,
            ], 409);
        }

        // Offline payment response.
        if ($data['payment_method'] === 'offline') {
            return response()->json([
                'message' => 'Offline payment request recorded and awaiting authorized verification.',
                'data' => array_merge($payment->toArray(), [
                    'status' => $payment->status,
                ]),
            ], 201);
        }

        try {
            $response = Http::withBasicAuth($keyId, $keySecret)
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post(
                    rtrim((string) config('payments.razorpay_base_url'), '/') . '/orders',
                    [
                        'amount' => $amountPaise,
                        'currency' => $currency,
                        'receipt' => $payment->payment_reference,
                        'notes' => [
                            'payment_reference' => $payment->payment_reference,
                            'purpose' => $payment->purpose,
                        ],
                    ]
                );

            if (!$response->successful() || !$response->json('id')) {
                DB::transaction(function () use ($payment, $attempt) {
                    $lockedPayment = Payment::whereKey($payment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedAttempt = PaymentAttempt::whereKey($attempt->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedAttempt->status = 'failed';
                    $lockedAttempt->failure_code = 'order_creation_failed';
                    $lockedAttempt->failure_message =
                        'Razorpay did not return a valid order.';
                    $lockedAttempt->completed_at = now();
                    $lockedAttempt->save();

                    $lockedPayment->status = 'failed';
                    $lockedPayment->save();
                });

                return response()->json([
                    'message' => 'Could not create the online payment order.',
                ], 502);
            }

            $orderId = (string) $response->json('id');

            DB::transaction(function () use ($payment, $attempt, $orderId) {
                $payment->provider_order_id = $orderId;
                $payment->save();

                $attempt->provider_order_id = $orderId;
                $attempt->status = 'created';
                $attempt->save();
            });

            return response()->json([
                'message' => 'Payment order created.',
                'data' => [
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->payment_reference,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'razorpay_order_id' => $orderId,
                    'razorpay_key_id' => $keyId,
                ],
            ], 201);

        } catch (Throwable $exception) {

            // Try to recover the Razorpay order using the unique payment reference.
            try {
                $recoveryResponse = Http::withBasicAuth($keyId, $keySecret)
                    ->acceptJson()
                    ->timeout(10)
                    ->get(
                        rtrim((string) config('payments.razorpay_base_url'), '/') . '/orders',
                        [
                            'receipt' => $payment->payment_reference,
                            'count' => 100,
                        ]
                    );

                if ($recoveryResponse->successful()) {
                    $orders = $recoveryResponse->json('items', []);

                    if (is_array($orders)) {
                        $matchingOrders = collect($orders)->filter(
                            function ($order) use ($payment, $amountPaise, $currency) {
                                return is_array($order)
                                    && !empty($order['id'])
                                    && ($order['receipt'] ?? null) === $payment->payment_reference
                                    && (int) ($order['amount'] ?? -1) === $amountPaise
                                    && strtoupper((string) ($order['currency'] ?? '')) === $currency
                                    && in_array(
                                        $order['status'] ?? '',
                                        ['created', 'attempted', 'paid'],
                                        true
                                    );
                            }
                        )->values();

                        // Recover only when exactly one valid order is found.
                        if ($matchingOrders->count() === 1) {
                            $recoveredOrderId = (string) $matchingOrders->first()['id'];

                            DB::transaction(function () use ($payment, $attempt, $recoveredOrderId) {
                                $lockedPayment = Payment::whereKey($payment->id)
                                    ->lockForUpdate()
                                    ->firstOrFail();

                                $lockedAttempt = PaymentAttempt::whereKey($attempt->id)
                                    ->lockForUpdate()
                                    ->firstOrFail();

                                $lockedPayment->provider_order_id = $recoveredOrderId;
                                $lockedPayment->save();

                                $lockedAttempt->provider_order_id = $recoveredOrderId;
                                $lockedAttempt->status = 'created';
                                $lockedAttempt->failure_code = null;
                                $lockedAttempt->failure_message = null;
                                $lockedAttempt->completed_at = null;
                                $lockedAttempt->save();
                            });

                            Log::info('Razorpay order recovered after uncertain creation response', [
                                'payment_id' => $payment->id,
                                'payment_reference' => $payment->payment_reference,
                                'order_id' => $recoveredOrderId,
                            ]);

                            return response()->json([
                                'message' => 'Payment order recovered successfully.',
                                'data' => [
                                    'payment_id' => $payment->id,
                                    'payment_reference' => $payment->payment_reference,
                                    'amount' => $payment->amount,
                                    'currency' => $payment->currency,
                                    'status' => 'pending',
                                    'razorpay_order_id' => $recoveredOrderId,
                                    'razorpay_key_id' => $keyId,
                                ],
                            ], 201);
                        }

                        Log::warning('Razorpay order recovery did not find exactly one valid order', [
                            'payment_id' => $payment->id,
                            'payment_reference' => $payment->payment_reference,
                            'matching_orders' => $matchingOrders->count(),
                        ]);
                    }
                } else {
                    Log::warning('Razorpay order recovery API returned an unsuccessful response', [
                        'payment_id' => $payment->id,
                        'http_status' => $recoveryResponse->status(),
                    ]);
                }
            } catch (Throwable $recoveryException) {
                Log::warning('Razorpay order recovery request failed', [
                    'payment_id' => $payment->id,
                    'exception' => get_class($recoveryException),
                ]);
            }

            // Recovery was unsuccessful. Keep the payment pending and attempt unknown.
            DB::transaction(function () use ($payment, $attempt) {
                $lockedPayment = Payment::whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedAttempt = PaymentAttempt::whereKey($attempt->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedAttempt->status = 'unknown';
                $lockedAttempt->failure_code = 'gateway_response_unknown';
                $lockedAttempt->failure_message =
                    'Unable to confirm whether Razorpay created the order.';
                $lockedAttempt->save();

                $lockedPayment->status = 'pending';
                $lockedPayment->save();
            });

            Log::warning('Razorpay order creation outcome unknown', [
                'payment_id' => $payment->id,
                'payment_reference' => $payment->payment_reference,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'Payment order status could not be confirmed. Please contact support before retrying.',
                'payment_reference' => $payment->payment_reference,
            ], 502);
        }

    }


    public function retry(Request $request, Payment $payment): JsonResponse
    {
        abort_unless(
            (int) $payment->customer_id === (int) $request->user()->id,
            403
        );

        if ($payment->payment_method !== 'razorpay') {
            return response()->json([
                'message' => 'Only Razorpay payments can be retried.',
            ], 422);
        }

        $keyId = (string) config('payments.razorpay_key_id');
        $keySecret = (string) config('payments.razorpay_key_secret');

        if ($keyId === '' || $keySecret === '') {
            return response()->json([
                'message' => 'Online payments are not configured.',
            ], 503);
        }

        // Create the next attempt only when the previous attempt definitively failed.
        [$payment, $attempt] = DB::transaction(function () use ($payment) {
            $lockedPayment = Payment::whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $lockedPayment->status === 'failed',
                422,
                'Only failed payments can be retried.'
            );

            $latestAttempt = PaymentAttempt::where('payment_id', $lockedPayment->id)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            abort_unless(
                $latestAttempt !== null && $latestAttempt->status === 'failed',
                422,
                'The latest payment attempt has not definitively failed.'
            );

            $attemptNumber = ((int) PaymentAttempt::where(
                'payment_id',
                $lockedPayment->id
            )->max('attempt_number')) + 1;

            $attempt = PaymentAttempt::create([
                'payment_id' => $lockedPayment->id,
                'attempt_number' => $attemptNumber,
                'payment_gateway' => 'razorpay',
                'status' => 'initiated',
                'amount' => $lockedPayment->amount,
                'currency' => $lockedPayment->currency,
                'initiated_at' => now(),
            ]);

            $lockedPayment->status = 'pending';
            $lockedPayment->provider_order_id = null;
            $lockedPayment->save();

            return [$lockedPayment, $attempt];
        });

        $amountPaise = $this->decimalToPaise((string) $payment->amount);

        try {
            $response = Http::withBasicAuth($keyId, $keySecret)
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post(
                    rtrim((string) config('payments.razorpay_base_url'), '/') . '/orders',
                    [
                        'amount' => $amountPaise,
                        'currency' => $payment->currency,
                        'receipt' => $payment->payment_reference . '-A' . $attempt->attempt_number,
                        'notes' => [
                            'payment_reference' => $payment->payment_reference,
                            'payment_id' => (string) $payment->id,
                            'attempt_number' => (string) $attempt->attempt_number,
                        ],
                    ]
                );

            if (!$response->successful() || !$response->json('id')) {
                DB::transaction(function () use ($payment, $attempt) {
                    $lockedAttempt = PaymentAttempt::whereKey($attempt->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedPayment = Payment::whereKey($payment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedAttempt->status = 'failed';
                    $lockedAttempt->failure_code = 'order_creation_failed';
                    $lockedAttempt->failure_message =
                        'Razorpay did not return a valid order.';
                    $lockedAttempt->completed_at = now();
                    $lockedAttempt->save();

                    $lockedPayment->status = 'failed';
                    $lockedPayment->save();
                });

                return response()->json([
                    'message' => 'Could not create the retry payment order.',
                ], 502);
            }

            $orderId = (string) $response->json('id');

            DB::transaction(function () use ($payment, $attempt, $orderId) {
                $lockedPayment = Payment::whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedAttempt = PaymentAttempt::whereKey($attempt->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedAttempt->provider_order_id = $orderId;
                $lockedAttempt->status = 'created';
                $lockedAttempt->save();

                $lockedPayment->provider_order_id = $orderId;
                $lockedPayment->status = 'pending';
                $lockedPayment->save();
            });

            return response()->json([
                'message' => 'Retry payment order created.',
                'data' => [
                    'payment_id' => $payment->id,
                    'payment_reference' => $payment->payment_reference,
                    'attempt_number' => $attempt->attempt_number,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => 'pending',
                    'razorpay_order_id' => $orderId,
                    'razorpay_key_id' => $keyId,
                ],
            ], 201);

        } catch (Throwable $exception) {

            // Recover the retry order using this attempt's unique receipt.
            $retryReceipt = $payment->payment_reference
                . '-A'
                . $attempt->attempt_number;

            try {
                $recoveryResponse = Http::withBasicAuth($keyId, $keySecret)
                    ->acceptJson()
                    ->timeout(10)
                    ->get(
                        rtrim(
                            (string) config('payments.razorpay_base_url'),
                            '/'
                        ) . '/orders',
                        [
                            'receipt' => $retryReceipt,
                            'count' => 100,
                        ]
                    );

                if ($recoveryResponse->successful()) {
                    $orders = $recoveryResponse->json('items', []);

                    if (is_array($orders)) {
                        $matchingOrders = collect($orders)->filter(
                            function ($order) use ($retryReceipt, $amountPaise, $payment) {
                                return is_array($order)
                                    && !empty($order['id'])
                                    && ($order['receipt'] ?? null) === $retryReceipt
                                    && (int) ($order['amount'] ?? -1) === $amountPaise
                                    && strtoupper(
                                        (string) ($order['currency'] ?? '')
                                    ) === strtoupper((string) $payment->currency)
                                    && in_array(
                                        $order['status'] ?? '',
                                        ['created', 'attempted', 'paid'],
                                        true
                                    );
                            }
                        )->values();

                        // Recover only when exactly one valid order is found.
                        if ($matchingOrders->count() === 1) {
                            $recoveredOrderId = (string) $matchingOrders->first()['id'];

                            DB::transaction(function () use ($payment, $attempt, $recoveredOrderId) {
                                $lockedPayment = Payment::whereKey($payment->id)
                                    ->lockForUpdate()
                                    ->firstOrFail();

                                $lockedAttempt = PaymentAttempt::whereKey($attempt->id)
                                    ->lockForUpdate()
                                    ->firstOrFail();

                                $lockedAttempt->provider_order_id = $recoveredOrderId;
                                $lockedAttempt->status = 'created';
                                $lockedAttempt->failure_code = null;
                                $lockedAttempt->failure_message = null;
                                $lockedAttempt->completed_at = null;
                                $lockedAttempt->save();

                                $lockedPayment->provider_order_id = $recoveredOrderId;
                                $lockedPayment->status = 'pending';
                                $lockedPayment->save();
                            });

                            Log::info(
                                'Razorpay retry order recovered after uncertain creation response',
                                [
                                    'payment_id' => $payment->id,
                                    'attempt_id' => $attempt->id,
                                    'attempt_number' => $attempt->attempt_number,
                                    'receipt' => $retryReceipt,
                                    'order_id' => $recoveredOrderId,
                                ]
                            );

                            return response()->json([
                                'message' => 'Retry payment order recovered successfully.',
                                'data' => [
                                    'payment_id' => $payment->id,
                                    'payment_reference' => $payment->payment_reference,
                                    'attempt_number' => $attempt->attempt_number,
                                    'amount' => $payment->amount,
                                    'currency' => $payment->currency,
                                    'status' => 'pending',
                                    'razorpay_order_id' => $recoveredOrderId,
                                    'razorpay_key_id' => $keyId,
                                ],
                            ], 201);
                        }

                        Log::warning(
                            'Razorpay retry recovery did not find exactly one valid order',
                            [
                                'payment_id' => $payment->id,
                                'attempt_id' => $attempt->id,
                                'receipt' => $retryReceipt,
                                'matching_orders' => $matchingOrders->count(),
                            ]
                        );
                    }
                } else {
                    Log::warning(
                        'Razorpay retry recovery API returned an unsuccessful response',
                        [
                            'payment_id' => $payment->id,
                            'attempt_id' => $attempt->id,
                            'http_status' => $recoveryResponse->status(),
                        ]
                    );
                }
            } catch (Throwable $recoveryException) {
                Log::warning('Razorpay retry recovery request failed', [
                    'payment_id' => $payment->id,
                    'attempt_id' => $attempt->id,
                    'exception' => get_class($recoveryException),
                ]);
            }

            // Recovery failed. Keep payment pending and attempt unknown.
            DB::transaction(function () use ($payment, $attempt) {
                $lockedAttempt = PaymentAttempt::whereKey($attempt->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedPayment = Payment::whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedAttempt->status = 'unknown';
                $lockedAttempt->failure_code = 'gateway_response_unknown';
                $lockedAttempt->failure_message =
                    'Unable to confirm whether Razorpay created the retry order.';
                $lockedAttempt->save();

                $lockedPayment->status = 'pending';
                $lockedPayment->save();
            });

            Log::warning('Razorpay retry order outcome unknown', [
                'payment_id' => $payment->id,
                'attempt_id' => $attempt->id,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'Retry order status could not be confirmed. Please contact support before retrying.',
                'payment_reference' => $payment->payment_reference,
            ], 502);
        }
    }


    public function verifyCheckout(Request $request, Payment $payment): JsonResponse
    {
        abort_unless((int) $payment->customer_id === (int) $request->user()->id, 403);
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:150'],
            'razorpay_payment_id' => ['required', 'string', 'max:150'],
            'razorpay_signature' => ['required', 'string', 'max:256'],
        ]);

        if (!$payment->provider_order_id || !hash_equals($payment->provider_order_id, $data['razorpay_order_id'])) {
            return response()->json(['message' => 'Payment order does not match.'], 422);
        }

        $secret = (string) config('payments.razorpay_key_secret');
        if ($secret === '') {
            return response()->json(['message' => 'Payment verification is not configured.'], 503);
        }

        $expected = hash_hmac('sha256', $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'], $secret);
        if (!hash_equals($expected, $data['razorpay_signature'])) {
            return response()->json(['message' => 'Invalid payment signature.'], 422);
        }

        // Checkout signature proves order/payment pairing. The webhook (or a server-side
        // provider fetch in a later hardening step) is the authoritative capture confirmation.
        return response()->json([
            'message' => 'Checkout signature verified. Payment remains pending until capture is confirmed.',
            'data' => ['payment_reference' => $payment->payment_reference, 'status' => $payment->status],
        ]);
    }


    public function webhook(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        $signature = (string) $request->header('X-Razorpay-Signature', '');
        $eventId = (string) $request->header('X-Razorpay-Event-Id', '');
        $secret = (string) config('payments.razorpay_webhook_secret');

        if ($secret === '' || $signature === '' || $eventId === '') {
            return response()->json([
                'message' => 'Webhook configuration or required headers are missing.',
            ], 400);
        }

        $expected = hash_hmac('sha256', $raw, $secret);

        if (!hash_equals($expected, $signature)) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], 400);
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload) || empty($payload['event'])) {
            return response()->json([
                'message' => 'Invalid webhook payload.',
            ], 400);
        }

        $eventType = (string) $payload['event'];

        $supportedEvents = [
            'payment.captured',
            'order.paid',
            'payment.failed',
        ];

        if (!in_array($eventType, $supportedEvents, true)) {
            return response()->json([
                'status' => 'ignored',
                'event' => $eventType,
            ]);
        }

        try {
            $result = DB::transaction(function () use ($payload, $eventId, $eventType) {
                $now = now();

                $inserted = DB::table('payment_events')->insertOrIgnore([
                    'provider' => 'razorpay',
                    'provider_event_id' => $eventId,
                    'event_type' => $eventType,
                    'processing_status' => 'received',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'processing_attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $event = PaymentEvent::where('provider', 'razorpay')
                    ->where('provider_event_id', $eventId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // A previously completed event must never be processed again.
                if (
                    $inserted === 0 &&
                    in_array($event->processing_status, ['processed', 'ignored'], true)
                ) {
                    return 'duplicate';
                }

                $event->processing_attempts =
                    (int) $event->processing_attempts + 1;

                $event->save();

                $paymentEntity = $payload['payload']['payment']['entity'] ?? null;
                $orderEntity = $payload['payload']['order']['entity'] ?? null;

                // Prefer payment entity when available.
                $entity = is_array($paymentEntity)
                    ? $paymentEntity
                    : $orderEntity;

                if (!is_array($entity)) {
                    $event->processing_status = 'failed';
                    $event->processing_error = 'Missing payment/order entity.';
                    $event->save();

                    return 'invalid';
                }

                // Payment entity contains order_id.
                // Order entity contains id, which is the order ID.
                $orderId = (string) (
                    $paymentEntity['order_id']
                    ?? $orderEntity['id']
                    ?? ''
                );

                if ($orderId === '') {
                    $event->processing_status = 'failed';
                    $event->processing_error = 'Missing Razorpay order ID.';
                    $event->save();

                    return 'invalid';
                }

                $attempt = PaymentAttempt::where('provider_order_id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if (!$attempt) {
                    $event->processing_status = 'failed';
                    $event->processing_error = 'No matching payment attempt.';
                    $event->save();

                    return 'invalid';
                }

                $payment = Payment::whereKey($attempt->payment_id)
                    ->lockForUpdate()
                    ->first();

                if (!$payment) {
                    $event->processing_status = 'failed';
                    $event->processing_error = 'No matching parent payment.';
                    $event->save();

                    return 'invalid';
                }

                $event->payment_id = $payment->id;
                $event->payment_attempt_id = $attempt->id;

                $event->payment_attempt_id = $attempt?->id;

                if (!$attempt) {
                    $event->processing_status = 'failed';
                    $event->processing_error = 'No matching payment attempt.';
                    $event->save();

                    return 'invalid';
                }

                // Never downgrade an already-paid payment.
// Validate amount and currency before checking payment status.
                $amountPaise = $eventType === 'order.paid'
                    ? (int) ($orderEntity['amount_paid'] ?? -1)
                    : (int) ($entity['amount'] ?? -1);

                $currency = strtoupper(
                    (string) ($entity['currency'] ?? '')
                );

                $expectedPaise = $this->decimalToPaise(
                    (string) $payment->amount
                );
                if (
                    $amountPaise !== $expectedPaise ||
                    $currency !== strtoupper($payment->currency)
                ) {
                    $event->processing_status = 'failed';
                    $event->processing_error = 'Amount or currency mismatch.';
                    $event->save();

                    return 'invalid';
                }
                // Never modify a refunded payment through a webhook.
                if (
                    $payment->status === 'refunded'
                    || $payment->refunded_at !== null
                ) {
                    $event->processing_status = 'ignored';
                    $event->processed_at = now();
                    $event->processing_error = 'Payment is already refunded.';
                    $event->save();

                    return 'ignored';
                }
                // Never downgrade an already-paid payment.
                if ($payment->status === 'paid') {
                    $event->processing_status = 'processed';
                    $event->processed_at = now();
                    $event->processing_error = null;
                    $event->save();

                    return 'processed';
                }

                // Extract payment ID only from a payment entity.
                // Never use the order entity ID as a payment ID.
                $providerPaymentId = is_array($paymentEntity)
                    ? (string) ($paymentEntity['id'] ?? '')
                    : '';

                if ($eventType === 'payment.failed') {
                    $attempt->status = 'failed';
                    $attempt->failure_code = $entity['error_code'] ?? 'payment_failed';
                    $attempt->failure_message =
                        $entity['error_description']
                        ?? $entity['error_reason']
                        ?? 'Payment failed at Razorpay.';

                    $attempt->completed_at = now();
                    $attempt->save();

                    // Only the latest attempt can mark the parent payment as failed.
                    $latestAttemptNumber = PaymentAttempt::where('payment_id', $payment->id)
                        ->max('attempt_number');

                    if ((int) $attempt->attempt_number === (int) $latestAttemptNumber) {
                        $payment->status = 'failed';
                        $payment->save();
                    }
                } else {
                    $payment->status = 'paid';
                    $payment->amount_paid = $payment->amount;
                    $payment->provider_payment_id =
                        $providerPaymentId !== '' ? $providerPaymentId : null;
                    $payment->paid_at = now();
                    $payment->save();

                    $attempt->status = 'captured';
                    $attempt->provider_payment_id =
                        $providerPaymentId !== '' ? $providerPaymentId : null;
                    $attempt->completed_at = now();
                    $attempt->save();
                }

                $event->processing_status = 'processed';
                $event->processed_at = now();
                $event->processing_error = null;
                $event->save();

                return 'processed';
            });

        } catch (Throwable $exception) {
            Log::warning('Razorpay webhook processing failed', [
                'event_id' => $eventId,
            ]);

            return response()->json([
                'message' => 'Webhook processing failed; provider may retry.',
            ], 500);
        }

        if ($result === 'invalid') {
            return response()->json([
                'message' => 'Webhook did not match a valid payment.',
            ], 422);
        }

        return response()->json([
            'status' => $result,
        ]);
    }


    public function confirmOffline(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        $data = $request->validate([
            'amount_received' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,2',
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $received = (string) $data['amount_received'];

        $receivedPaise = $this->decimalToPaise($received);
        $expectedPaise = $this->decimalToPaise((string) $payment->amount);

        abort_unless(
            $receivedPaise === $expectedPaise,
            422,
            'Received amount must match the payment amount.'
        );

        $received = number_format($receivedPaise / 100, 2, '.', '');
        abort_unless($payment->payment_method === 'offline', 422, 'Only offline payments can be confirmed here.');
        abort_unless($payment->status === 'pending', 422, 'Only pending payments can be confirmed.');

        DB::transaction(function () use ($request, $payment, $received, $data) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending', 422, 'Payment is no longer pending.');
            $locked->status = 'paid';
            $locked->amount_paid = $received;
            $locked->paid_at = now();
            $locked->recorded_by = $request->user()->id;
            $locked->internal_notes = $data['notes'] ?? null;
            $locked->save();
        });

        return response()->json(['message' => 'Offline payment confirmed.', 'data' => $payment->fresh()]);
    }

    public function reconcile(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        abort_unless(
            $payment->payment_method === 'razorpay',
            422,
            'Only Razorpay payments can be reconciled.'
        );

        if (
            $payment->status === 'refunded'
            || $payment->refunded_at !== null
        ) {
            return response()->json([
                'message' => 'Refunded payments cannot be reconciled.',
                'data' => [
                    'payment_id' => $payment->id,
                    'status' => 'refunded',
                ],
            ], 409);
        }

        if ($payment->status === 'paid') {
            return response()->json([
                'message' => 'Payment is already marked as paid.',
                'data' => [
                    'payment_id' => $payment->id,
                    'status' => 'paid',
                ],
            ]);
        }

        $keyId = (string) config('payments.razorpay_key_id');
        $keySecret = (string) config('payments.razorpay_key_secret');

        if ($keyId === '' || $keySecret === '') {
            return response()->json([
                'message' => 'Razorpay credentials are not configured.',
            ], 503);
        }

        $attempt = PaymentAttempt::where('payment_id', $payment->id)
            ->orderByDesc('attempt_number')
            ->first();

        if (!$attempt) {
            return response()->json([
                'message' => 'No payment attempt exists for this payment.',
            ], 422);
        }

        if ($attempt->status === 'captured') {
            return response()->json([
                'message' => 'The latest attempt is already marked as captured. Review the payment records.',
            ], 409);
        }

        $amountPaise = $this->decimalToPaise((string) $payment->amount);

        $currency = strtoupper((string) $payment->currency);

        $receipt = (int) $attempt->attempt_number === 1
            ? $payment->payment_reference
            : $payment->payment_reference . '-A' . $attempt->attempt_number;

        $baseUrl = rtrim(
            (string) config('payments.razorpay_base_url'),
            '/'
        );

        try {
            // Step 1: Find the Razorpay order.
            if ($attempt->provider_order_id) {
                $orderResponse = Http::withBasicAuth($keyId, $keySecret)
                    ->acceptJson()
                    ->timeout(15)
                    ->get(
                        $baseUrl . '/orders/' . $attempt->provider_order_id
                    );

                if (!$orderResponse->successful()) {
                    Log::warning('Razorpay reconciliation order lookup failed', [
                        'payment_id' => $payment->id,
                        'attempt_id' => $attempt->id,
                        'http_status' => $orderResponse->status(),
                    ]);

                    return response()->json([
                        'message' => 'Unable to retrieve the Razorpay order.',
                    ], 502);
                }

                $order = $orderResponse->json();
            } else {
                // Recover an order when its creation response was uncertain.
                $orderResponse = Http::withBasicAuth($keyId, $keySecret)
                    ->acceptJson()
                    ->timeout(15)
                    ->get($baseUrl . '/orders', [
                        'receipt' => $receipt,
                        'count' => 100,
                    ]);

                if (!$orderResponse->successful()) {
                    return response()->json([
                        'message' => 'Unable to search Razorpay orders.',
                    ], 502);
                }

                $orders = $orderResponse->json('items', []);

                if (!is_array($orders)) {
                    return response()->json([
                        'message' => 'Invalid response received from Razorpay.',
                    ], 502);
                }

                $matchingOrders = collect($orders)->filter(
                    function ($order) use ($receipt, $amountPaise, $currency) {
                        return is_array($order)
                            && !empty($order['id'])
                            && ($order['receipt'] ?? null) === $receipt
                            && (int) ($order['amount'] ?? -1) === $amountPaise
                            && strtoupper((string) ($order['currency'] ?? '')) === $currency;
                    }
                )->values();

                if ($matchingOrders->count() === 0) {
                    return response()->json([
                        'message' => 'No matching Razorpay order was found.',
                        'data' => [
                            'payment_id' => $payment->id,
                            'status' => 'pending',
                        ],
                    ]);
                }

                if ($matchingOrders->count() !== 1) {
                    return response()->json([
                        'message' => 'Multiple matching Razorpay orders were found. Manual investigation is required.',
                    ], 409);
                }

                $order = $matchingOrders->first();
            }

            if (
                !is_array($order)
                || empty($order['id'])
                || ($order['receipt'] ?? null) !== $receipt
                || (int) ($order['amount'] ?? -1) !== $amountPaise
                || strtoupper((string) ($order['currency'] ?? '')) !== $currency
            ) {
                Log::warning('Razorpay reconciliation order validation failed', [
                    'payment_id' => $payment->id,
                    'attempt_id' => $attempt->id,
                ]);

                return response()->json([
                    'message' => 'Razorpay order does not match the local payment.',
                ], 422);
            }

            $orderId = (string) $order['id'];

            // Step 2: Fetch actual payment records for this order.
            $paymentsResponse = Http::withBasicAuth($keyId, $keySecret)
                ->acceptJson()
                ->timeout(15)
                ->get($baseUrl . '/orders/' . $orderId . '/payments');

            if (!$paymentsResponse->successful()) {
                return response()->json([
                    'message' => 'Unable to retrieve Razorpay payment records.',
                ], 502);
            }

            $providerPayments = $paymentsResponse->json('items', []);

            if (!is_array($providerPayments)) {
                return response()->json([
                    'message' => 'Invalid payment records received from Razorpay.',
                ], 502);
            }

            // Step 3: Find captured payments.
            $capturedPayments = collect($providerPayments)->filter(
                function ($providerPayment) use ($orderId) {
                    return is_array($providerPayment)
                        && ($providerPayment['status'] ?? null) === 'captured'
                        && ($providerPayment['order_id'] ?? null) === $orderId;
                }
            )->values();

            if ($capturedPayments->count() > 1) {
                return response()->json([
                    'message' => 'Multiple captured payments were found for this order. Manual investigation is required.',
                ], 409);
            }

            if ($capturedPayments->count() === 1) {
                $capturedPayment = $capturedPayments->first();

                $capturedAmount = (int) ($capturedPayment['amount'] ?? -1);

                $capturedCurrency = strtoupper(
                    (string) ($capturedPayment['currency'] ?? '')
                );

                $providerPaymentId = (string) ($capturedPayment['id'] ?? '');

                if (
                    $capturedAmount !== $amountPaise
                    || $capturedCurrency !== $currency
                    || $providerPaymentId === ''
                ) {
                    return response()->json([
                        'message' => 'Captured Razorpay payment does not match the expected amount or currency.',
                    ], 422);
                }

                // Step 4: Update payment and attempt atomically.
// Step 4: Update payment and attempt atomically.
                $reconciliationResult = DB::transaction(function () use ($payment, $attempt, $orderId, $providerPaymentId) {
                    $lockedPayment = Payment::whereKey($payment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedAttempt = PaymentAttempt::whereKey($attempt->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    // Never change a refunded payment back to paid.
                    if (
                        $lockedPayment->status === 'refunded'
                        || $lockedPayment->refunded_at !== null
                    ) {
                        return 'refunded';
                    }

                    // Do not process a payment that is already paid.
                    if ($lockedPayment->status === 'paid') {
                        return 'already_paid';
                    }

                    $lockedAttempt->provider_order_id = $orderId;
                    $lockedAttempt->provider_payment_id = $providerPaymentId;
                    $lockedAttempt->status = 'captured';
                    $lockedAttempt->failure_code = null;
                    $lockedAttempt->failure_message = null;
                    $lockedAttempt->completed_at = now();
                    $lockedAttempt->save();

                    $lockedPayment->provider_order_id = $orderId;
                    $lockedPayment->provider_payment_id = $providerPaymentId;
                    $lockedPayment->status = 'paid';
                    $lockedPayment->amount_paid = $lockedPayment->amount;
                    $lockedPayment->paid_at = now();
                    $lockedPayment->save();

                    return 'reconciled';
                });

                // Handle refunded payment.
                if ($reconciliationResult === 'refunded') {
                    return response()->json([
                        'message' => 'Refunded payments cannot be reconciled.',
                        'data' => [
                            'payment_id' => $payment->id,
                            'status' => 'refunded',
                        ],
                    ], 409);
                }

                // Handle already-paid payment.
                if ($reconciliationResult === 'already_paid') {
                    return response()->json([
                        'message' => 'Payment is already marked as paid.',
                        'data' => [
                            'payment_id' => $payment->id,
                            'status' => 'paid',
                        ],
                    ]);
                }

                Log::info('Payment reconciled successfully with Razorpay', [
                    'payment_id' => $payment->id,
                    'attempt_id' => $attempt->id,
                    'order_id' => $orderId,
                    'provider_payment_id' => $providerPaymentId,
                ]);

                return response()->json([
                    'message' => 'Payment successfully reconciled and marked as paid.',
                    'data' => [
                        'payment_id' => $payment->id,
                        'payment_reference' => $payment->payment_reference,
                        'status' => 'paid',
                        'razorpay_order_id' => $orderId,
                        'razorpay_payment_id' => $providerPaymentId,
                    ],
                ]);
            }

            // Step 5: Mark failed only when all returned payment records failed.
// Step 5: No captured payment was found.
// Keep the payment pending because individual failed attempts
// do not necessarily mean that the Razorpay order is closed.

            DB::transaction(function () use ($payment, $attempt, $orderId) {
                $lockedPayment = Payment::whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedAttempt = PaymentAttempt::whereKey($attempt->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Do not overwrite a completed or refunded payment.
                if (
                    $lockedPayment->status === 'paid'
                    || $lockedPayment->status === 'refunded'
                    || $lockedPayment->refunded_at !== null
                ) {
                    return;
                }

                $lockedAttempt->provider_order_id = $orderId;
                $lockedAttempt->save();

                $lockedPayment->provider_order_id = $orderId;
                $lockedPayment->save();
            });

            $latestPayment = $payment->fresh();

            if ($latestPayment->status === 'paid') {
                return response()->json([
                    'message' => 'Payment is already marked as paid.',
                    'data' => [
                        'payment_id' => $latestPayment->id,
                        'status' => 'paid',
                    ],
                ]);
            }

            return response()->json([
                'message' => 'Payment is still pending confirmation from Razorpay.',
                'data' => [
                    'payment_id' => $latestPayment->id,
                    'status' => $latestPayment->status,
                    'razorpay_order_id' => $orderId,
                ],
            ]);

        } catch (Throwable $exception) {
            Log::error('Payment reconciliation failed', [
                'payment_id' => $payment->id,
                'attempt_id' => $attempt->id,
                'exception' => get_class($exception),
            ]);

            return response()->json([
                'message' => 'Payment reconciliation could not be completed. Please try again later.',
            ], 502);
        }
    }

    private function isIdempotencyUniqueConstraintViolation(
        QueryException $exception
    ): bool {
        $errorInfo = $exception->errorInfo ?? [];

        $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());
        $errorMessage = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($errorMessage, 'idempotency');
    }
    private function decimalToPaise(string $amount): int
    {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        [$rupees, $paise] = array_pad(
            explode('.', $amount, 2),
            2,
            ''
        );

        return ((int) $rupees * 100)
            + (int) str_pad($paise, 2, '0');
    }
    private function canView(Request $request, Payment $payment): bool
    {
        $user = $request->user();
        if ($user->hasRole('admin') || (int) $payment->customer_id === (int) $user->id) {
            return true;
        }

        return $user->hasRole('owner')
            && $payment->property()
                ->where('owner_id', $user->id)
                ->exists();
    }
}
