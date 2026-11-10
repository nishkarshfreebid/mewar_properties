<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\VisitController;
use App\Http\Controllers\Api\V1\EnquiryController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PropertyMediaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AccountProvisioningController;
use App\Http\Controllers\Api\V1\AdminDashboardController;

Route::prefix('v1')->name('api.v1.')->group(function () {

    Route::post('/internal/provision-account', [
        AccountProvisioningController::class,
        'store',
    ])->middleware('throttle:3,1')->name('internal.provision-account');
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
        // ADMIN DASHBOARD
        Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])
            ->middleware('permission:dashboard.view')
            ->name('admin.dashboard');
        // ADMIN PROPERTY MANAGEMENT
        Route::get('/admin/properties', [PropertyController::class, 'adminIndex'])
            ->middleware('permission:property.view_published|property.update_assigned')
            ->name('admin.properties.index');

        Route::get('/admin/property-owners', [PropertyController::class, 'ownerOptions'])
            ->middleware('permission:property.create')
            ->name('admin.property-owners');
        // ==========================================
// USER MANAGEMENT
// ==========================================

        Route::prefix('admin')->name('admin.')->group(function () {

            Route::get('/users', [AdminUserController::class, 'index'])
                ->middleware('permission:user.view')
                ->name('users.index');

            Route::post('/users', [AdminUserController::class, 'store'])
                ->middleware('permission:user.create')
                ->name('users.store');

            Route::get('/users/{user}', [AdminUserController::class, 'show'])
                ->middleware('permission:user.view')
                ->name('users.show');

            Route::put('/users/{user}', [AdminUserController::class, 'update'])
                ->middleware('permission:user.update')
                ->name('users.update');

            Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])
                ->middleware('permission:user.update')
                ->name('users.status');

            Route::put('/users/{user}/roles', [AdminUserController::class, 'assignRoles'])
                ->middleware('permission:user.assign_roles')
                ->name('users.roles');

            Route::get('/roles', [AdminUserController::class, 'roles'])
                ->middleware('permission:user.view')
                ->name('roles.index');

            Route::get('/roles/{role}', [AdminUserController::class, 'showRole'])
                ->middleware('permission:user.view')
                ->name('roles.show');

            Route::put('/roles/{role}/permissions', [AdminUserController::class, 'updateRolePermissions'])
                ->middleware('permission:user.manage_roles')
                ->name('roles.permissions');
        });
        // ==========================================
        // PROPERTY MANAGEMENT
        // ==========================================

        // View properties owned by or assigned to the authenticated user.
        Route::get('/properties/mine', [PropertyController::class, 'mine'])
            ->middleware('permission:property.manage_own|property.update_assigned')
            ->name('properties.mine');

        // Create property.
        Route::post('/properties', [PropertyController::class, 'store'])
            ->middleware('permission:property.create')
            ->name('properties.store');

        // View a single property.
        // Record-level access is checked by PropertyPolicy.
        Route::get('/properties/{property}', [PropertyController::class, 'show'])
            ->name('properties.show');

        // Update property.
        Route::match(['put', 'patch'], '/properties/{property}', [PropertyController::class, 'update'])
            ->middleware('permission:property.manage_own|property.update_assigned')
            ->name('properties.update');

        // Delete property.
        Route::delete('/properties/{property}', [PropertyController::class, 'destroy'])
            ->middleware('permission:property.manage_own')
            ->name('properties.destroy');

        // Approve property.
        Route::post('/properties/{property}/approve', [PropertyController::class, 'approve'])
            ->middleware('permission:property.approve')
            ->name('properties.approve');

        // Reject property.
        Route::post('/properties/{property}/reject', [PropertyController::class, 'reject'])
            ->middleware('permission:property.approve')
            ->name('properties.reject');

        // Publish property.
        Route::post('/properties/{property}/publish', [PropertyController::class, 'publish'])
            ->middleware('permission:property.publish')
            ->name('properties.publish');

        // Unpublish property.
        Route::post('/properties/{property}/unpublish', [PropertyController::class, 'unpublish'])
            ->middleware('permission:property.publish')
            ->name('properties.unpublish');

        // Assign or remove property agent.
        Route::put('/properties/{property}/agent', [PropertyController::class, 'assignAgent'])
            ->middleware('permission:property.assign_agent')
            ->name('properties.assign-agent');

        // Upload property images and videos.
        Route::post('/properties/{property}/media', [PropertyMediaController::class, 'store'])
            ->middleware('permission:property.manage_own|property.update_assigned')
            ->name('properties.media.store');

        // Delete property media.
        Route::delete('/properties/{property}/media/{media}', [PropertyMediaController::class, 'destroy'])
            ->middleware('permission:property.manage_own|property.update_assigned')
            ->name('properties.media.destroy');
        Route::patch(
            '/properties/{property}/media/{media}/primary',
            [PropertyMediaController::class, 'setPrimary']
        )
            ->middleware('permission:property.manage_own|property.update_assigned')
            ->name('properties.media.primary');

        // ==========================================
        // ENQUIRY MANAGEMENT
        // ==========================================

        Route::get('/enquiries', [EnquiryController::class, 'index'])
            ->middleware('permission:enquiry.manage_all|enquiry.view_own|enquiry.view_assigned|enquiry.view_own_property')
            ->name('enquiries.index');

        Route::post('/enquiries', [EnquiryController::class, 'store'])
            ->middleware('permission:enquiry.create')
            ->name('enquiries.store');

        Route::get('/enquiries/{enquiry}', [EnquiryController::class, 'show'])
            ->middleware('permission:enquiry.manage_all|enquiry.view_own|enquiry.view_assigned|enquiry.view_own_property')
            ->name('enquiries.show');
        Route::put('/enquiries/{enquiry}/assign', [EnquiryController::class, 'assign'])
            ->middleware('permission:enquiry.assign')
            ->name('enquiries.assign');

        Route::patch('/enquiries/{enquiry}/status', [EnquiryController::class, 'updateStatus'])
            ->middleware('permission:enquiry.manage_all|enquiry.manage_assigned|enquiry.manage_own_property')
            ->name('enquiries.status');

        Route::post('/enquiries/{enquiry}/activities', [EnquiryController::class, 'addActivity'])
            ->middleware('permission:enquiry.manage_all|enquiry.manage_assigned|enquiry.manage_own_property')
            ->name('enquiries.activities.store');

        Route::get('/enquiries/follow-ups', [
            \App\Http\Controllers\Api\V1\EnquiryFollowUpController::class,
            'index',
        ])
            ->middleware('permission:enquiry.manage_all|enquiry.view_assigned')
            ->name('enquiries.follow-ups');


        // ==========================================
        // VISIT MANAGEMENT
        // ==========================================

        Route::get('/visits', [VisitController::class, 'index'])
            ->middleware('permission:visit.view_own|visit.view_own_property|visit.manage_assigned')
            ->name('visits.index');

        Route::post('/visits', [VisitController::class, 'store'])
            ->middleware('permission:visit.create')
            ->name('visits.store');

        Route::get('/visits/{visit}', [VisitController::class, 'show'])
            ->middleware('permission:visit.view_own|visit.view_own_property|visit.manage_assigned')
            ->name('visits.show');

        Route::patch('/visits/{visit}/status', [VisitController::class, 'updateStatus'])
            ->middleware('permission:visit.view_own|visit.manage_assigned|visit.manage_own_property')
            ->name('visits.status');
        Route::put('/visits/{visit}/assign', [VisitController::class, 'assign'])
            ->middleware('permission:visit.assign')
            ->name('visits.assign');
        Route::patch('/visits/{visit}/reschedule', [VisitController::class, 'reschedule'])
            ->middleware('permission:visit.view_own|visit.manage_assigned|visit.manage_own_property')
            ->name('visits.reschedule');


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



    }); // End auth:sanctum group
}); // End v1 route group

Route::get('/user', fn(Request $request) => $request->user())
    ->middleware('auth:sanctum');

// Razorpay webhooks authenticate with an HMAC signature rather than a user session.
Route::post('/webhooks/razorpay', [PaymentController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('webhooks.razorpay');