<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\EnquiryActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnquiryFollowUpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $isAdmin = $user->hasRole('admin')
            && $user->hasPermission('enquiry.manage_all');

        $isAgentOrStaff = $user->hasRole('agent')
            || $user->hasRole('staff');

        abort_unless(
            $isAdmin || (
                $isAgentOrStaff
                && $user->hasPermission('enquiry.view_assigned')
            ),
            403,
            'You are not authorized to view enquiry follow-ups.'
        );

        $filters = $request->validate([
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $now = now();
        $today = $now->toDateString();
        $endOfToday = $now->copy()->endOfDay();

        $applyEnquiryScope = function ($query) use (
            $user,
            $isAdmin,
            $filters
        ) {
            $query->whereNotIn('status', ['closed', 'lost']);

            if (!$isAdmin) {
                $query->where('assigned_agent_id', $user->id);
            }

            if ($isAdmin && !empty($filters['assigned_user_id'])) {
                $query->where(
                    'assigned_agent_id',
                    $filters['assigned_user_id']
                );
            }

            if (!empty($filters['search'])) {
                $search = trim($filters['search']);

                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%")
                        ->orWhereHas('property', function ($property) use ($search) {
                            $property->where(
                                'title',
                                'like',
                                "%{$search}%"
                            );
                        });
                });
            }
        };

        $baseActivities = EnquiryActivity::query()
            ->whereNotNull('follow_up_at')
            ->whereHas('enquiry', $applyEnquiryScope);

        $overdueCount = (clone $baseActivities)
            ->where('follow_up_at', '<', $now)
            ->count();

        $todayCount = (clone $baseActivities)
            ->whereDate('follow_up_at', $today)
            ->where('follow_up_at', '>=', $now)
            ->count();

        $upcomingCount = (clone $baseActivities)
            ->where('follow_up_at', '>', $endOfToday)
            ->count();

        $withoutFollowUpQuery = Enquiry::query()
            ->with([
                'property:id,title',
                'assignedAgent:id,name,email',
            ])
            ->where($applyEnquiryScope)
            ->whereDoesntHave('activities', function ($activity) {
                $activity->whereNotNull('follow_up_at');
            });

        $withoutFollowUpCount = (clone $withoutFollowUpQuery)->count();

        $getActivities = function (string $bucket) use (
            $baseActivities,
            $now,
            $today,
            $endOfToday
        ) {
            $query = clone $baseActivities;

            switch ($bucket) {
                case 'overdue':
                    $query->where('follow_up_at', '<', $now);
                    break;

                case 'today':
                    $query
                        ->whereDate('follow_up_at', $today)
                        ->where('follow_up_at', '>=', $now);
                    break;

                case 'upcoming':
                    $query->where('follow_up_at', '>', $endOfToday);
                    break;
            }

            return $query
                ->with([
                    'user:id,name',
                    'enquiry.property:id,title,slug',
                    'enquiry.customer:id,name,email',
                    'enquiry.assignedAgent:id,name,email',
                ])
                ->orderBy('follow_up_at')
                ->limit(10)
                ->get();
        };

        return response()->json([
            'success' => true,
            'data' => [
                'counts' => [
                    'overdue' => $overdueCount,
                    'today' => $todayCount,
                    'upcoming' => $upcomingCount,
                    'without_follow_up' => $withoutFollowUpCount,
                ],
                'overdue' => $getActivities('overdue'),
                'today' => $getActivities('today'),
                'upcoming' => $getActivities('upcoming'),
                'without_follow_up' => $withoutFollowUpQuery
                    ->latest()
                    ->limit(10)
                    ->get(),
            ],
        ]);
    }
}
