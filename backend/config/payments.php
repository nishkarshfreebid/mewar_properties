<?php

return [
    'currency' => env('PAYMENT_CURRENCY', 'INR'),
    // Keep these at 0 until the business confirms actual prices.
    'booking_token_amount' => (int) env('PAYMENT_BOOKING_TOKEN_AMOUNT_PAISE', 0),
    'service_fee_amount' => (int) env('PAYMENT_SERVICE_FEE_AMOUNT_PAISE', 0),
    'razorpay_key_id' => env('RAZORPAY_KEY_ID'),
    'razorpay_key_secret' => env('RAZORPAY_KEY_SECRET'),
    'razorpay_webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    'razorpay_base_url' => 'https://api.razorpay.com/v1',
];
