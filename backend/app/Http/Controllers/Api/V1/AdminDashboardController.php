<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Dashboard data is restricted to administrators.
        abort_unless(
            $request->user()->hasRole('admin'),
            403,
            'Administrator access required.'
        );

        $now = now();

        // Property statistics.
        $properties = [
            'total' => Property::count(),

            'pending_approval' => Property::where(
                'approval_status',
                'pending'
            )->count(),

            'approved' => Property::where(
                'approval_status',
                'approved'
            )->count(),

            'published' => Property::where('approval_status', 'approved')
                ->where('publication_status', 'published')
                ->count(),

            'draft' => Property::where(
                'publication_status',
                'draft'
            )->count(),
        ];

        // Enquiry statistics.
        $enquiries = [
            'total' => Enquiry::count(),

            'new' => Enquiry::where('status', 'new')->count(),

            'contacted' => Enquiry::where('status', 'contacted')->count(),

            'qualified' => Enquiry::where('status', 'qualified')->count(),

            'open' => Enquiry::whereNotIn(
                'status',
                ['closed', 'lost']
            )->count(),

            'closed' => Enquiry::where('status', 'closed')->count(),

            'lost' => Enquiry::where('status', 'lost')->count(),
        ];

        // Visit statistics.
        $visits = [
            'total' => Visit::count(),

            'upcoming' => Visit::whereIn(
                'status',
                ['scheduled', 'confirmed']
            )->where('scheduled_at', '>=', $now)->count(),

            'scheduled' => Visit::where(
                'status',
                'scheduled'
            )->count(),

            'confirmed' => Visit::where(
                'status',
                'confirmed'
            )->count(),

            'completed' => Visit::where(
                'status',
                'completed'
            )->count(),

            'cancelled' => Visit::where(
                'status',
                'cancelled'
            )->count(),
        ];

        // Payment statistics.
        $paidPayments = Payment::where('status', 'paid');

        $payments = [
            'total' => Payment::count(),

            'paid' => Payment::where('status', 'paid')->count(),

            'pending' => Payment::where('status', 'pending')->count(),

            'failed' => Payment::where('status', 'failed')->count(),

            'refunded' => Payment::where('status', 'refunded')->count(),

            'collected_amount' => (float) $paidPayments->sum('amount_paid'),
        ];

        // Latest properties.
        $recentProperties = Property::query()
            ->select([
                'id',
                'title',
                'city',
                'price',
                'approval_status',
                'publication_status',
                'created_at',
            ])
            ->latest()
            ->limit(5)
            ->get();

        // Latest enquiries.
        $recentEnquiries = Enquiry::query()
            ->with('property:id,title')
            ->select([
                'id',
                'property_id',
                'customer_name',
                'customer_email',
                'status',
                'created_at',
            ])
            ->latest()
            ->limit(5)
            ->get();

        // Upcoming visits.
        $upcomingVisits = Visit::query()
            ->with([
                'property:id,title',
                'customer:id,name',
            ])
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where('scheduled_at', '>=', $now)
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get([
                'id',
                'property_id',
                'customer_id',
                'scheduled_at',
                'status',
            ]);

        // Recent payments.
        $recentPayments = Payment::query()
            ->with([
                'property:id,title',
                'customer:id,name',
            ])
            ->latest()
            ->limit(5)
            ->get([
                'id',
                'property_id',
                'customer_id',
                'payment_reference',
                'amount',
                'amount_paid',
                'currency',
                'status',
                'paid_at',
                'created_at',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully.',

            'data' => [
                'statistics' => [
                    'properties' => $properties,
                    'enquiries' => $enquiries,
                    'visits' => $visits,
                    'payments' => $payments,
                ],

                'recent_properties' => $recentProperties,
                'recent_enquiries' => $recentEnquiries,
                'upcoming_visits' => $upcomingVisits,
                'recent_payments' => $recentPayments,

                'generated_at' => $now->toISOString(),
            ],
        ]);
    }
}
