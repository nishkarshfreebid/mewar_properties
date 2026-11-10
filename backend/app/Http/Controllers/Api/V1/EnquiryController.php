<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\EnquiryActivity;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnquiryController extends Controller
{
    private const STATUSES = [
        'new',
        'contacted',
        'qualified',
        'closed',
        'lost',
    ];

    private const ACTIVITY_TYPES = [
        'call',
        'email',
        'whatsapp',
        'note',
        'meeting',
        'follow_up',
    ];

    /**
     * Check whether the user can view a particular enquiry.
     */
    private function canView(Request $request, Enquiry $enquiry): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        if (
            $user->hasRole('admin')
            && $user->hasPermission('enquiry.manage_all')
        ) {
            return true;
        }

        $property = $enquiry->property;

        if (
            $user->hasRole('customer')
            && $user->hasPermission('enquiry.view_own')
            && (int) $enquiry->customer_id === (int) $user->id
        ) {
            return true;
        }

        if (
            $user->hasRole('owner')
            && $user->hasPermission('enquiry.view_own_property')
            && $property !== null
            && (int) $property->owner_id === (int) $user->id
        ) {
            return true;
        }

        if (
            $user->hasRole('agent')
            && $user->hasPermission('enquiry.view_assigned')
            && (int) $enquiry->assigned_agent_id === (int) $user->id
        ) {
            return true;
        }

        if (
            $user->hasRole('staff')
            && $user->hasPermission('enquiry.view_assigned')
            && (int) $enquiry->assigned_agent_id === (int) $user->id
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check whether the user can manage a particular enquiry.
     */
    private function canManage(Request $request, Enquiry $enquiry): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return $user->hasPermission('enquiry.manage_all');
        }

        $property = $enquiry->property;

        if ($user->hasRole('staff')) {
            return $user->hasPermission('enquiry.manage_assigned')
                && (int) $enquiry->assigned_agent_id === (int) $user->id;
        }

        if ($user->hasRole('agent')) {
            return $user->hasPermission('enquiry.manage_assigned')
                && (int) $enquiry->assigned_agent_id === (int) $user->id;
        }

        if ($user->hasRole('owner')) {
            return $user->hasPermission('enquiry.manage_own_property')
                && $property !== null
                && (int) $property->owner_id === (int) $user->id;
        }

        return false;
    }

    /**
     * List enquiries visible to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasAnyPermission([
                'enquiry.manage_all',
                'enquiry.view_own',
                'enquiry.view_assigned',
                'enquiry.view_own_property',
            ]),
            403,
            'You are not authorized to view enquiries.'
        );

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Enquiry::query()->with([
            'property:id,title,slug,owner_id,assigned_agent_id',
            'customer:id,name,email',
            'assignedAgent:id,name,email',
        ]);

        if (
            $user->hasRole('admin')
            && $user->hasPermission('enquiry.manage_all')
        ) {
            // Admin can view all enquiries.
        } else {
            $query->where(function ($scope) use ($user) {

                if (
                    $user->hasRole('customer')
                    && $user->hasPermission('enquiry.view_own')
                ) {
                    $scope->orWhere('customer_id', $user->id);
                }

                if (
                    $user->hasRole('owner')
                    && $user->hasPermission('enquiry.view_own_property')
                ) {
                    $scope->orWhereHas('property', function ($property) use ($user) {
                        $property->where('owner_id', $user->id);
                    });
                }

                if (
                    $user->hasRole('agent')
                    && $user->hasPermission('enquiry.view_assigned')
                ) {
                    $scope->orWhere('assigned_agent_id', $user->id);
                }

                if (
                    $user->hasRole('staff')
                    && $user->hasPermission('enquiry.view_assigned')
                ) {
                    $scope->orWhere('assigned_agent_id', $user->id);
                }
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($builder) use ($search) {
                $builder->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhereHas('property', function ($property) use ($search) {
                        $property->where('title', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = $filters['per_page'] ?? 15;

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($perPage),
        ]);
    }

    /**
     * Create an enquiry from a customer submission or an internal manual entry.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isCustomer = $user->hasRole('customer');
        $isAdmin = $user->hasRole('admin');
        $isAgent = $user->hasRole('agent');
        $isStaff = $user->hasRole('staff');
        $isInternalUser = $isAdmin || $isAgent || $isStaff;

        abort_unless(
            ($isCustomer || $isInternalUser)
            && $user->hasPermission('enquiry.create'),
            403,
            'You are not authorized to create enquiries.'
        );

        $rules = [
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'message' => ['nullable', 'string', 'max:5000'],
        ];

        if ($isCustomer) {
            $rules['customer_phone'] = ['nullable', 'string', 'max:30'];
            $rules['source'] = ['prohibited'];
            $rules['assigned_user_id'] = ['prohibited'];
        } else {
            $rules['customer_name'] = ['required', 'string', 'max:150'];
            $rules['customer_phone'] = ['required', 'string', 'max:30'];
            $rules['customer_email'] = ['nullable', 'email', 'max:255'];
            $rules['source'] = [
                'required',
                'string',
                Rule::in(['phone_call', 'whatsapp', 'walk_in', 'reference', 'other']),
            ];
            $rules['assigned_user_id'] = $isAdmin
                ? ['nullable', 'integer', 'exists:users,id']
                : ['prohibited'];
        }

        $data = $request->validate($rules);

        $propertyQuery = Property::query()->whereKey($data['property_id']);

        if ($isCustomer) {
            $propertyQuery
                ->where('approval_status', 'approved')
                ->where('publication_status', 'published');
        } elseif ($isAgent) {
            // Agents can create enquiries only for properties assigned to them.
            $propertyQuery->where('assigned_agent_id', $user->id);
        } elseif ($isStaff) {
            // Staff can create enquiries for published properties available in their workspace.
            $propertyQuery
                ->where('approval_status', 'approved')
                ->where('publication_status', 'published');
        }

        $property = $propertyQuery->firstOrFail();

        $assignedUser = null;
        if ($isAgent || $isStaff) {
            $assignedUser = $user;
        } elseif ($isAdmin && !empty($data['assigned_user_id'])) {
            $assignedUser = User::query()->findOrFail($data['assigned_user_id']);
            abort_unless(
                (bool) $assignedUser->is_active
                && ($assignedUser->hasRole('agent') || $assignedUser->hasRole('staff')),
                422,
                'Enquiries can only be assigned to active agents or staff.'
            );
        }

        $enquiry = DB::transaction(function () use ($user, $property, $data, $isCustomer, $assignedUser) {
            $enquiry = Enquiry::create([
                'property_id' => $property->id,
                'customer_id' => $isCustomer ? $user->id : null,
                'assigned_agent_id' => $assignedUser?->id,
                'customer_name' => $isCustomer ? $user->name : trim($data['customer_name']),
                'customer_email' => $isCustomer ? $user->email : ($data['customer_email'] ?? null),
                'customer_phone' => $data['customer_phone'] ?? null,
                'message' => $data['message'] ?? null,
                'status' => 'new',
                'source' => $isCustomer ? 'website' : $data['source'],
            ]);

            EnquiryActivity::create([
                'enquiry_id' => $enquiry->id,
                'user_id' => $user->id,
                'activity_type' => 'created',
                'subject' => $isCustomer ? 'Enquiry submitted' : 'Manual enquiry created',
                'notes' => $isCustomer ? null : 'Enquiry entered manually by ' . $user->name,
                'new_status' => 'new',
                'metadata' => [
                    'creation_channel' => $isCustomer ? 'customer_portal' : 'admin_panel',
                    'source' => $isCustomer ? 'website' : $data['source'],
                ],
            ]);

            return $enquiry;
        });

        return response()->json([
            'success' => true,
            'message' => 'Enquiry created successfully.',
            'data' => $enquiry->load([
                'property:id,title,slug,owner_id,assigned_agent_id',
                'customer:id,name,email',
                'assignedAgent:id,name,email',
            ]),
        ], 201);
    }

    /**
     * View enquiry details.
     */
    public function show(Request $request, Enquiry $enquiry): JsonResponse
    {
        abort_unless(
            $this->canView($request, $enquiry),
            403,
            'You are not authorized to view this enquiry.'
        );

        $user = $request->user();

        $relations = [
            'property:id,title,slug,owner_id,assigned_agent_id',
            'customer:id,name,email',
            'assignedAgent:id,name,email',
            'visits',
        ];


        // Only customer-only users cannot see internal activities.
        $isCustomerOnly = $user->hasRole('customer')
            && !$user->hasRole('admin')
            && !$user->hasRole('owner')
            && !$user->hasRole('agent')
            && !$user->hasRole('staff');

        if (!$isCustomerOnly) {
            $relations[] = 'activities.user:id,name';
        }


        return response()->json([
            'success' => true,
            'data' => $enquiry->load($relations),
        ]);
    }

    /**
     * Admin assigns or removes an enquiry assignment.
     */
    public function assign(Request $request, Enquiry $enquiry): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole('admin')
            && $user->hasPermission('enquiry.assign'),
            403,
            'You are not authorized to assign enquiries.'
        );

        $data = $request->validate([
            'assigned_user_id' => [
                'present',
                'nullable',
                'integer',
                'exists:users,id',
            ],
        ]);

        $assignedUser = null;

        if ($data['assigned_user_id'] !== null) {
            $assignedUser = User::findOrFail($data['assigned_user_id']);

            abort_unless(
                (bool) $assignedUser->is_active,
                422,
                'Cannot assign an enquiry to an inactive user.'
            );

            abort_unless(
                $assignedUser->hasRole('agent')
                || $assignedUser->hasRole('staff'),
                422,
                'Enquiries can only be assigned to agents or staff.'
            );
        }

        DB::transaction(function () use ($enquiry, $assignedUser, $user) {
            $lockedEnquiry = Enquiry::query()
                ->whereKey($enquiry->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldAssignedUserId = $lockedEnquiry->assigned_agent_id;
            $newAssignedUserId = $assignedUser?->id;

            if (
                (int) $oldAssignedUserId === (int) $newAssignedUserId
            ) {
                return;
            }

            $lockedEnquiry->assigned_agent_id = $newAssignedUserId;
            $lockedEnquiry->save();

            EnquiryActivity::create([
                'enquiry_id' => $lockedEnquiry->id,
                'user_id' => $user->id,
                'activity_type' => 'assignment_changed',
                'subject' => $assignedUser
                    ? 'Enquiry assigned'
                    : 'Enquiry unassigned',
                'notes' => $assignedUser
                    ? 'Assigned to ' . $assignedUser->name
                    : 'Enquiry assignment removed',
                'metadata' => [
                    'old_assigned_user_id' => $oldAssignedUserId,
                    'new_assigned_user_id' => $newAssignedUserId,
                ],
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Enquiry assignment updated successfully.',
            'data' => $enquiry->fresh()->load([
                'property:id,title,slug',
                'customer:id,name,email',
                'assignedAgent:id,name,email',
            ]),
        ]);
    }

    /**
     * Allowed status transitions.
     */
    private function allowedNextStatuses(string $currentStatus): array
    {
        return match ($currentStatus) {
            'new' => ['contacted', 'lost'],
            'contacted' => ['qualified', 'closed', 'lost'],
            'qualified' => ['closed', 'lost'],
            default => [],
        };
    }

    /**
     * Update enquiry status.
     */
    public function updateStatus(
        Request $request,
        Enquiry $enquiry
    ): JsonResponse {
        abort_unless(
            $this->canManage($request, $enquiry),
            403,
            'You are not authorized to manage this enquiry.'
        );

        $user = $request->user();

        $data = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(self::STATUSES),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'follow_up_at' => ['nullable', 'date'],
            'closing_notes' => [
                'nullable',
                'required_if:status,closed,lost',
                'string',
                'max:5000',
            ],
        ]);

        return DB::transaction(function () use ($enquiry, $data, $user) {
            $lockedEnquiry = Enquiry::query()
                ->whereKey($enquiry->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $lockedEnquiry->status;
            $newStatus = $data['status'];

            abort_if(
                $oldStatus === $newStatus,
                422,
                'The enquiry already has this status.'
            );

            $isReopening = in_array(
                $oldStatus,
                ['closed', 'lost'],
                true
            );

            if ($isReopening) {
                abort_unless(
                    $user->hasRole('admin')
                    && $user->hasPermission('enquiry.manage_all')
                    && $newStatus === 'contacted',
                    422,
                    'Only an administrator can reopen a closed or lost enquiry, and it must be reopened as contacted.'
                );

                abort_if(
                    empty(trim($data['notes'] ?? '')),
                    422,
                    'A reason is required when reopening an enquiry.'
                );
            } else {
                $allowedStatuses = $this->allowedNextStatuses($oldStatus);

                // Admin can directly close an active enquiry.
                $isAdmin = $user->hasRole('admin')
                    && $user->hasPermission('enquiry.manage_all');

                if (
                    $isAdmin
                    && in_array($oldStatus, ['new', 'contacted', 'qualified'], true)
                ) {
                    $allowedStatuses[] = 'closed';
                }

                abort_unless(
                    in_array($newStatus, $allowedStatuses, true),
                    422,
                    "Invalid enquiry status transition from {$oldStatus} to {$newStatus}."
                );
            }

            $lockedEnquiry->status = $newStatus;

            if (in_array($newStatus, ['closed', 'lost'], true)) {
                $lockedEnquiry->closed_at = now();
                $lockedEnquiry->closing_notes = $data['closing_notes'];
            } else {
                $lockedEnquiry->closed_at = null;
                $lockedEnquiry->closing_notes = null;
            }

            if ($newStatus === 'contacted') {
                $lockedEnquiry->last_contacted_at = now();
            }

            $lockedEnquiry->save();

            EnquiryActivity::create([
                'enquiry_id' => $lockedEnquiry->id,
                'user_id' => $user->id,
                'activity_type' => 'status_changed',
                'subject' => $isReopening
                    ? 'Enquiry reopened'
                    : 'Enquiry status updated',
                'notes' => $data['notes'] ?? null,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'follow_up_at' => $data['follow_up_at'] ?? null,
                'metadata' => [
                    'reopened' => $isReopening,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Enquiry status updated successfully.',
                'data' => $lockedEnquiry->fresh(),
            ]);
        });
    }

    /**
     * Follow-up dashboard for Admin, Agent and Staff.
     */
    public function followUps(Request $request): JsonResponse
    {
        $user = $request->user();

        $isAdmin = $user->hasRole('admin')
            && $user->hasPermission('enquiry.manage_all');

        abort_unless(
            $isAdmin
            || (
                ($user->hasRole('agent') || $user->hasRole('staff'))
                && $user->hasPermission('enquiry.view_assigned')
            ),
            403,
            'You are not authorized to view follow-ups.'
        );

        $filters = $request->validate([
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $now = now();
        $today = $now->toDateString();
        $endOfToday = $now->copy()->endOfDay();

        // Admin can see all active enquiries.
        // Agent and Staff can see only enquiries assigned to themselves.
        $enquiryScope = function ($query) use ($user, $isAdmin, $filters) {
            $query->whereNotIn('status', ['closed', 'lost']);

            if (!$isAdmin) {
                $query->where('assigned_agent_id', $user->id);
            } elseif (!empty($filters['assigned_user_id'])) {
                $query->where(
                    'assigned_agent_id',
                    $filters['assigned_user_id']
                );
            }

            if (!empty($filters['search'])) {
                $search = $filters['search'];

                $query->where(function ($builder) use ($search) {
                    $builder->where('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%")
                        ->orWhereHas('property', function ($property) use ($search) {
                            $property->where('title', 'like', "%{$search}%");
                        });
                });
            }
        };

        $baseActivities = EnquiryActivity::query()
            ->whereNotNull('follow_up_at')
            ->whereHas('enquiry', $enquiryScope);

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
            ->where($enquiryScope)
            ->whereDoesntHave('activities', function ($activity) {
                $activity->whereNotNull('follow_up_at');
            });

        $withoutFollowUpCount = (clone $withoutFollowUpQuery)->count();

        $activityQuery = function (string $bucket) use ($baseActivities, $now, $today, $endOfToday) {
            $query = clone $baseActivities;

            match ($bucket) {
                'overdue' => $query->where('follow_up_at', '<', $now),
                'today' => $query->whereDate('follow_up_at', $today)
                    ->where('follow_up_at', '>=', $now),
                'upcoming' => $query->where('follow_up_at', '>', $endOfToday),
                default => $query,
            };

            return $query->with([
                'user:id,name',
                'enquiry.property:id,title',
                'enquiry.assignedAgent:id,name,email',
            ])->orderBy('follow_up_at');
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
                'overdue' => $activityQuery('overdue')->limit(10)->get(),
                'today' => $activityQuery('today')->limit(10)->get(),
                'upcoming' => $activityQuery('upcoming')->limit(10)->get(),
                'without_follow_up' => $withoutFollowUpQuery
                    ->latest()
                    ->limit(10)
                    ->get(),
            ],
        ]);
    }

    /**
     * Add an internal activity to an enquiry.
     */
    public function addActivity(
        Request $request,
        Enquiry $enquiry
    ): JsonResponse {
        abort_unless(
            $this->canManage($request, $enquiry),
            403,
            'You are not authorized to add activities to this enquiry.'
        );

        $data = $request->validate([
            'activity_type' => [
                'required',
                'string',
                Rule::in(self::ACTIVITY_TYPES),
            ],
            'subject' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        $activity = $enquiry->activities()->create([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Enquiry activity added successfully.',
            'data' => $activity->load('user:id,name'),
        ], 201);
    }
}
