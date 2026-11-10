<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\Property;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\User;
use Carbon\Carbon;

class VisitController extends Controller
{


    private function canView(Request $r, Visit $v): bool
    {
        $u = $r->user();

        // Staff can only view visits directly assigned to them.
        if ($u->hasRole('staff')) {
            return (int) $v->assigned_agent_id === (int) $u->id;
        }

        $p = $v->property;

        return $u->hasRole('admin')
            || (int) $v->customer_id === (int) $u->id
            || (int) $v->assigned_agent_id === (int) $u->id
            || ($p !== null && (
                (int) $p->owner_id === (int) $u->id
                || (int) $p->assigned_agent_id === (int) $u->id
            ));
    }

    private function canManage(Request $r, Visit $v): bool
    {
        $u = $r->user();
        $p = $v->property;

        if ($u->hasRole('admin')) {
            return true;
        }

        // Staff: direct assignment only.
        if ($u->hasRole('staff')) {
            return $u->hasPermission('visit.manage_assigned')
                && (int) $v->assigned_agent_id === (int) $u->id;
        }

        // Owner: visits for their own properties.
        if ($u->hasRole('owner')) {
            return $u->hasPermission('visit.manage_own_property')
                && $p !== null
                && (int) $p->owner_id === (int) $u->id;
        }

        // Agent: directly assigned visits or assigned properties.
        if ($u->hasRole('agent')) {
            return $u->hasPermission('visit.manage_assigned')
                && (
                    (int) $v->assigned_agent_id === (int) $u->id
                    || ($p !== null && (int) $p->assigned_agent_id === (int) $u->id)
                );
        }

        return false;
    }


    private function assertNoAgentConflict(
        int $agentId,
        Carbon $scheduledAt,
        int $durationMinutes,
        ?int $ignoreVisitId = null
    ): void {
        $newEnd = $scheduledAt->copy()->addMinutes($durationMinutes);

        $query = Visit::where('assigned_agent_id', $agentId)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where('scheduled_at', '<', $newEnd);

        if ($ignoreVisitId !== null) {
            $query->where('id', '!=', $ignoreVisitId);
        }

        $conflictingVisit = $query->get([
            'id',
            'scheduled_at',
            'duration_minutes',
        ])->first(function (Visit $existing) use ($scheduledAt, $newEnd) {
            $existingStart = Carbon::parse($existing->scheduled_at);
            $existingEnd = $existingStart->copy()
                ->addMinutes($existing->duration_minutes);

            return $existingStart->lt($newEnd)
                && $existingEnd->gt($scheduledAt);
        });

        if ($conflictingVisit !== null) {
            abort(
                422,
                'This agent already has another visit scheduled during this time.'
            );
        }
    }

    private function recordActivity(
        Visit $visit,
        int $userId,
        string $type,
        ?string $subject = null,
        ?string $notes = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $metadata = null
    ): void {
        $visit->activities()->create([
            'user_id' => $userId,
            'activity_type' => $type,
            'subject' => $subject,
            'notes' => $notes,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'metadata' => $metadata,
        ]);
    }
    private function prepareVisitResponse(Request $request, Visit $visit): Visit
    {
        $user = $request->user();

        $isAdmin = $user->hasRole('admin');
        $isOperational = $isAdmin
            || $user->hasRole('agent')
            || $user->hasRole('staff');

        if (!$isOperational) {
            $visit->makeHidden(['internal_notes']);
        }

        $visit->loadMissing('activities.user:id,name,email');

        foreach ($visit->activities as $activity) {
            // Do not expose actor email addresses in activity history.
            $activity->user?->makeHidden(['email']);

            if (!$isAdmin) {
                $activity->makeHidden(['metadata']);
            }

            if (!$isOperational) {
                $activity->makeHidden(['notes']);
            }
        }

        return $visit;
    }

    private function formatVisitResponse(Request $request, Visit $visit): array
    {
        $user = $request->user();

        $isAdmin = $user->hasRole('admin');
        $isAgentOrStaff = $user->hasRole('agent') || $user->hasRole('staff');

        $data = $visit->toArray();

        // Internal operational notes are only visible to admins and assigned
        // operational users who have access to this visit.
        if (!$isAdmin && !$isAgentOrStaff) {
            unset($data['internal_notes']);
        }

        $activities = $visit->activities->map(function ($activity) use ($isAdmin, $isAgentOrStaff) {
            $item = [
                'id' => $activity->id,
                'activity_type' => $activity->activity_type,
                'subject' => $activity->subject,
                'created_at' => $activity->created_at,
                'user' => $activity->user,
            ];

            if ($isAdmin) {
                return array_merge($item, [
                    'notes' => $activity->notes,
                    'old_status' => $activity->old_status,
                    'new_status' => $activity->new_status,
                    'metadata' => $activity->metadata,
                ]);
            }

            if ($isAgentOrStaff) {
                return array_merge($item, [
                    'notes' => $activity->notes,
                    'old_status' => $activity->old_status,
                    'new_status' => $activity->new_status,
                ]);
            }

            // Customers and owners receive a restricted activity history.
            return array_merge($item, [
                'old_status' => $activity->old_status,
                'new_status' => $activity->new_status,
            ]);
        });

        $data['activities'] = $activities;

        return $data;
    }


    public function index(Request $r): JsonResponse
    {
        $u = $r->user();

        $q = Visit::with([
            'property:id,title,slug,owner_id,assigned_agent_id',
            'customer:id,name,email',
            'assignedAgent:id,name',
        ]);

        if (!$u->hasRole('admin')) {

            $hasAccessRole = $u->hasRole('customer')
                || $u->hasRole('owner')
                || $u->hasRole('agent')
                || $u->hasRole('staff');

            abort_unless($hasAccessRole, 403);

            $q->where(function ($scope) use ($u) {

                // Customer: own visits.
                if ($u->hasRole('customer')) {
                    $scope->orWhere('customer_id', $u->id);
                }

                // Owner: visits for owned properties.
                if ($u->hasRole('owner')) {
                    $scope->orWhereHas('property', function ($property) use ($u) {
                        $property->where('owner_id', $u->id);
                    });
                }

                // Agent: directly assigned visits or assigned properties.
                if ($u->hasRole('agent')) {
                    $scope->orWhere('assigned_agent_id', $u->id)
                        ->orWhereHas('property', function ($property) use ($u) {
                            $property->where('assigned_agent_id', $u->id);
                        });
                }
                // Staff: directly assigned visits only.
                if ($u->hasRole('staff')) {
                    $scope->orWhere('assigned_agent_id', $u->id);
                }
            });
        }

        $visits = $q->orderByDesc('scheduled_at')->paginate(15);

        $visits->getCollection()->transform(
            fn(Visit $visit) => $this->prepareVisitResponse($r, $visit)
        );

        return response()->json([
            'data' => $visits,
        ]);
    }


    public function store(Request $r): JsonResponse
    {
        $u = $r->user();

        abort_unless(
            $u->hasRole('customer'),
            403,
            'Only customers can schedule visits.'
        );

        $d = $r->validate([
            'property_id' => [
                'required',
                'integer',
                'exists:properties,id',
            ],
            'enquiry_id' => [
                'nullable',
                'integer',
                'exists:enquiries,id',
            ],
            'scheduled_at' => [
                'required',
                'date',
                'after:now',
            ],
            'duration_minutes' => [
                'sometimes',
                'integer',
                'min:15',
                'max:240',
            ],
            'customer_notes' => [
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $p = Property::whereKey($d['property_id'])
            ->where('approval_status', 'approved')
            ->where('publication_status', 'published')
            ->firstOrFail();

        $eid = $d['enquiry_id'] ?? null;

        if ($eid !== null) {
            Enquiry::whereKey($eid)
                ->where('customer_id', $u->id)
                ->where('property_id', $p->id)
                ->firstOrFail();
        }

        $scheduledAt = Carbon::parse($d['scheduled_at']);
        $duration = $d['duration_minutes'] ?? 30;
        $agentId = $p->assigned_agent_id;

        $visit = DB::transaction(function () use ($u, $p, $eid, $scheduledAt, $duration, $agentId, $d) {
            if ($agentId !== null) {
                User::whereKey($agentId)->lockForUpdate()->firstOrFail();

                $this->assertNoAgentConflict(
                    (int) $agentId,
                    $scheduledAt,
                    $duration
                );
            }

            $visit = Visit::create([
                'property_id' => $p->id,
                'customer_id' => $u->id,
                'enquiry_id' => $eid,
                'assigned_agent_id' => $agentId,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $duration,
                'status' => 'scheduled',
                'customer_notes' => $d['customer_notes'] ?? null,
            ]);

            $this->recordActivity(
                $visit,
                (int) $u->id,
                'created',
                'Visit requested',
                'Customer scheduled a property visit.',
                null,
                'scheduled',
                [
                    'scheduled_at' => $scheduledAt->toDateTimeString(),
                    'duration_minutes' => $duration,
                    'assigned_agent_id' => $agentId,
                ]
            );

            return $visit;
        });

        return response()->json([
            'message' => 'Property visit scheduled.',
            'data' => $this->prepareVisitResponse(
                $r,
                $visit->load([
                    'property:id,title,slug,owner_id,assigned_agent_id',
                    'customer:id,name,email',
                    'assignedAgent:id,name',
                    'activities',
                ])
            ),
        ], 201);
    }

    public function assign(Request $r, Visit $visit): JsonResponse
    {
        abort_unless(
            $r->user()->hasRole('admin')
            && $r->user()->hasPermission('visit.assign'),
            403,
            'You are not authorized to assign visits.'
        );

        $d = $r->validate([
            'assigned_user_id' => [
                'present',
                'nullable',
                'integer',
                'exists:users,id',
            ],
        ]);

        $assignedUserId = $d['assigned_user_id'];

        if ($assignedUserId !== null) {
            $eligible = User::whereKey($assignedUserId)
                ->whereHas('roles', function ($query) {
                    $query->whereIn('name', ['agent', 'staff']);
                })
                ->exists();

            abort_unless(
                $eligible,
                422,
                'A visit can only be assigned to an agent or staff member.'
            );
        }

        $updatedVisit = DB::transaction(function () use ($visit, $assignedUserId, $r) {
            $lockedVisit = Visit::whereKey($visit->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedVisit->status, ['completed', 'cancelled'], true)) {
                abort(422, 'Completed or cancelled visits cannot be reassigned.');
            }

            $oldAgentId = $lockedVisit->assigned_agent_id;

            if ((int) $oldAgentId === (int) $assignedUserId) {
                return $lockedVisit->fresh([
                    'property:id,title,slug,owner_id,assigned_agent_id',
                    'customer:id,name,email',
                    'assignedAgent:id,name',
                    'activities',
                ]);
            }

            if ($assignedUserId !== null) {
                User::whereKey($assignedUserId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertNoAgentConflict(
                    (int) $assignedUserId,
                    Carbon::parse($lockedVisit->scheduled_at),
                    (int) $lockedVisit->duration_minutes,
                    (int) $lockedVisit->id
                );
            }

            $lockedVisit->assigned_agent_id = $assignedUserId;
            $lockedVisit->save();

            $this->recordActivity(
                $lockedVisit,
                (int) $r->user()->id,
                'assignment_changed',
                'Visit assignment changed',
                null,
                null,
                null,
                [
                    'old_assigned_user_id' => $oldAgentId,
                    'new_assigned_user_id' => $assignedUserId,
                ]
            );

            return $lockedVisit->fresh([
                'property:id,title,slug,owner_id,assigned_agent_id',
                'customer:id,name,email',
                'assignedAgent:id,name',
                'activities',
            ]);
        });

        return response()->json([
            'message' => 'Visit assignment updated successfully.',
            'data' => $this->prepareVisitResponse($r, $updatedVisit),
        ]);
    }


    public function show(Request $r, Visit $visit): JsonResponse
    {
        abort_unless($this->canView($r, $visit), 403);

        return response()->json([
            'data' => $this->prepareVisitResponse(
                $r,
                $visit->load([
                    'property:id,title,slug,owner_id,assigned_agent_id',
                    'customer:id,name,email',
                    'assignedAgent:id,name',
                    'enquiry',
                    'activities',
                ])
            ),
        ]);
    }

    public function reschedule(Request $r, Visit $visit): JsonResponse
    {
        $user = $r->user();

        $isCustomer = (int) $visit->customer_id === (int) $user->id;

        // Customers can reschedule only their own scheduled visits.
        if ($isCustomer) {
            abort_unless($user->hasRole('customer'), 403);
            abort_unless(
                $visit->status === 'scheduled',
                422,
                'Only scheduled visits can be rescheduled by customers.'
            );
        } else {
            abort_unless(
                $this->canManage($r, $visit),
                403,
                'You are not authorized to reschedule this visit.'
            );
        }

        $d = $r->validate([
            'scheduled_at' => [
                'required',
                'date',
                'after:now',
            ],
            'duration_minutes' => [
                'sometimes',
                'integer',
                'min:15',
                'max:240',
            ],
        ]);

        $scheduledAt = Carbon::parse($d['scheduled_at']);
        $duration = $d['duration_minutes'] ?? $visit->duration_minutes;

        $updatedVisit = DB::transaction(function () use ($r, $visit, $scheduledAt, $duration, $isCustomer) {
            $lockedVisit = Visit::whereKey($visit->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedVisit->load([
                'property:id,owner_id,assigned_agent_id',
            ]);

            $user = $r->user();

            $isCustomer = (int) $lockedVisit->customer_id === (int) $user->id;

            if ($isCustomer) {
                abort_unless(
                    $user->hasRole('customer')
                    && $lockedVisit->status === 'scheduled',
                    403,
                    'You cannot reschedule this visit.'
                );
            } else {
                abort_unless(
                    $this->canManage($r, $lockedVisit),
                    403,
                    'You are not authorized to reschedule this visit.'
                );

                abort_unless(
                    in_array($lockedVisit->status, ['scheduled', 'confirmed'], true),
                    422,
                    'Only scheduled or confirmed visits can be rescheduled.'
                );
            }

            $oldScheduledAt = Carbon::parse($lockedVisit->scheduled_at);
            $oldDuration = (int) $lockedVisit->duration_minutes;

            $agentId = $lockedVisit->assigned_agent_id;

            if ($agentId !== null) {
                User::whereKey($agentId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertNoAgentConflict(
                    (int) $agentId,
                    $scheduledAt,
                    $duration,
                    (int) $lockedVisit->id
                );
            }

            $lockedVisit->scheduled_at = $scheduledAt;
            $lockedVisit->duration_minutes = $duration;
            $lockedVisit->save();

            $this->recordActivity(
                $lockedVisit,
                (int) $user->id,
                'rescheduled',
                'Visit rescheduled',
                'Visit schedule updated.',
                null,
                null,
                [
                    'old_scheduled_at' => $oldScheduledAt->toDateTimeString(),
                    'new_scheduled_at' => $scheduledAt->toDateTimeString(),
                    'old_duration_minutes' => $oldDuration,
                    'new_duration_minutes' => $duration,
                ]
            );

            return $lockedVisit->fresh([
                'property:id,title,slug,owner_id,assigned_agent_id',
                'customer:id,name,email',
                'assignedAgent:id,name',
                'activities',
            ]);
        });

        return response()->json([
            'message' => 'Visit rescheduled successfully.',
            'data' => $this->prepareVisitResponse($r, $updatedVisit),
        ]);
    }


    public function updateStatus(Request $r, Visit $visit): JsonResponse
    {
        $u = $r->user();

        $isCustomer = (int) $visit->customer_id === (int) $u->id;

        if ($isCustomer) {
            $d = $r->validate([
                'status' => [
                    'required',
                    Rule::in(['cancelled']),
                ],
                'cancellation_reason' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ]);
        } else {
            abort_unless($this->canManage($r, $visit), 403);

            $d = $r->validate([
                'status' => [
                    'required',
                    Rule::in([
                        'confirmed',
                        'completed',
                        'cancelled',
                    ]),
                ],
                'cancellation_reason' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
                'internal_notes' => [
                    'nullable',
                    'string',
                    'max:3000',
                ],
            ]);
        }

        $updatedVisit = DB::transaction(function () use ($r, $visit, $d) {
            $lockedVisit = Visit::whereKey($visit->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedVisit->load('property');

            $u = $r->user();

            $isCustomer = (int) $lockedVisit->customer_id === (int) $u->id;

            if (!$isCustomer) {
                abort_unless($this->canManage($r, $lockedVisit), 403);
            }

            $allowedTransitions = [
                'scheduled' => ['confirmed', 'cancelled'],
                'confirmed' => ['completed', 'cancelled'],
                'completed' => [],
                'cancelled' => [],
            ];

            $oldStatus = $lockedVisit->status;
            $newStatus = $d['status'];

            abort_unless(
                in_array($newStatus, $allowedTransitions[$oldStatus] ?? [], true),
                422,
                "Visit cannot transition from {$oldStatus} to {$newStatus}."
            );

            // Customers can only cancel their own visits.
            if ($isCustomer) {
                abort_unless($newStatus === 'cancelled', 403);
            }

            $lockedVisit->status = $newStatus;

            if ($newStatus === 'confirmed') {
                $lockedVisit->confirmed_at = now();
            }

            if ($newStatus === 'completed') {
                $lockedVisit->completed_at = now();
            }

            if ($newStatus === 'cancelled') {
                $lockedVisit->cancelled_at = now();
                $lockedVisit->cancellation_reason =
                    $d['cancellation_reason'] ?? null;
            }

            if (!$isCustomer && array_key_exists('internal_notes', $d)) {
                $lockedVisit->internal_notes = $d['internal_notes'];
            }

            $lockedVisit->save();

            $this->recordActivity(
                $lockedVisit,
                (int) $u->id,
                'status_changed',
                'Visit status changed',
                $newStatus === 'cancelled'
                ? ($d['cancellation_reason'] ?? null)
                : null,
                $oldStatus,
                $newStatus,
                [
                    'cancellation_reason' => $d['cancellation_reason'] ?? null,
                ]
            );

            return $lockedVisit->fresh([
                'property:id,title,slug,owner_id,assigned_agent_id',
                'customer:id,name,email',
                'assignedAgent:id,name',
                'activities',
            ]);
        });

        return response()->json([
            'message' => 'Visit status updated successfully.',
            'data' => $this->prepareVisitResponse($r, $updatedVisit),
        ]);
    }

}
