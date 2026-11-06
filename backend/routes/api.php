<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\VisitController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PropertyMediaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
// Public property listing
Route::get('/properties', [PropertyController::class, 'index'])
    ->name('properties.index');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1')->name('register');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/properties/mine', [PropertyController::class, 'mine'])
            ->name('properties.mine');

        Route::post('/properties/{property}/approve', [PropertyController::class, 'approve'])
            ->name('properties.approve');
        Route::post('/properties/{property}/reject', [PropertyController::class, 'reject'])
            ->name('properties.reject');
        Route::post('/properties/{property}/publish', [PropertyController::class, 'publish'])
            ->name('properties.publish');
        Route::post('/properties/{property}/unpublish', [PropertyController::class, 'unpublish'])
            ->name('properties.unpublish');
        Route::put('/properties/{property}/agent', [PropertyController::class, 'assignAgent'])
            ->name('properties.assign-agent');

                Route::post(
            '/properties/{property}/media',
            [PropertyMediaController::class, 'store']
        )->name('properties.media.store');

        Route::delete(
            '/properties/{property}/media/{media}',
            [PropertyMediaController::class, 'destroy']
        )->name('properties.media.destroy');
        Route::get('/enquiries', [EnquiryController::class, 'index'])->name('enquiries.index');
        Route::post('/enquiries', [EnquiryController::class, 'store'])->name('enquiries.store');
        Route::get('/enquiries/{enquiry}', [EnquiryController::class, 'show'])->name('enquiries.show');
        Route::patch('/enquiries/{enquiry}/status', [EnquiryController::class, 'updateStatus'])->name('enquiries.status');
        Route::post('/enquiries/{enquiry}/activities', [EnquiryController::class, 'addActivity'])->name('enquiries.activities.store');
        Route::get('/visits', [VisitController::class, 'index'])->name('visits.index');
        Route::post('/visits', [VisitController::class, 'store'])->name('visits.store');
        Route::get('/visits/{visit}', [VisitController::class, 'show'])->name('visits.show');
        Route::patch('/visits/{visit}/status', [VisitController::class, 'updateStatus'])->name('visits.status');


        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('/payments', [PaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
Route::post('/payments/{payment}/retry', [PaymentController::class, 'retry'])
    ->middleware('throttle:10,1')
    ->name('payments.retry');
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::post('/payments/{payment}/verify-checkout', [PaymentController::class, 'verifyCheckout'])->middleware('throttle:10,1')->name('payments.verify-checkout');
Route::post('/payments/{payment}/confirm-offline', [PaymentController::class, 'confirmOffline'])
    ->middleware('throttle:10,1')
    ->name('payments.confirm-offline');
Route::post('/payments/{payment}/reconcile', [PaymentController::class, 'reconcile'])
    ->middleware('throttle:10,1')
    ->name('payments.reconcile');
        Route::apiResource('properties', PropertyController::class)
    ->except(['index']);
    });
});

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

// Razorpay webhooks authenticate with an HMAC signature rather than a user session.
Route::post('/webhooks/razorpay', [PaymentController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('webhooks.razorpay');