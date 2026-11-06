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

class VisitController extends Controller
{
    private function canView(Request $r, Visit $v): bool
    {
        $u = $r->user();
        $p = $v->property;

        return $u->hasRole('admin')
            || (int) $v->customer_id === (int) $u->id
            || (int) $v->assigned_agent_id === (int) $u->id
            || ($p && (int) $p->owner_id === (int) $u->id)
            || ($p && (int) $p->assigned_agent_id === (int) $u->id);
    }

    private function canManage(Request $r, Visit $v): bool
    {
        $u = $r->user();
        $p = $v->property;

        return $u->hasRole('admin')
            || ($p && $u->hasRole('owner')
                && (int) $p->owner_id === (int) $u->id)
            || (
                $u->hasRole('agent')
                && (
                    (int) $v->assigned_agent_id === (int) $u->id
                    || ($p && (int) $p->assigned_agent_id === (int) $u->id)
                )
            );
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
                || $u->hasRole('agent');

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
            });
        }

        return response()->json([
            'data' => $q->orderByDesc('scheduled_at')->paginate(15),
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

        $v = Visit::create([
            'property_id' => $p->id,
            'customer_id' => $u->id,
            'enquiry_id' => $eid,
            'assigned_agent_id' => $p->assigned_agent_id,
            'scheduled_at' => $d['scheduled_at'],
            'duration_minutes' => $d['duration_minutes'] ?? 30,
            'status' => 'scheduled',
            'customer_notes' => $d['customer_notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Property visit scheduled.',
            'data' => $v->load([
                'property',
                'customer:id,name,email',
            ]),
        ], 201);
    }

    public function show(Request $r, Visit $visit): JsonResponse
    {
        abort_unless($this->canView($r, $visit), 403);

        return response()->json([
            'data' => $visit->load([
                'property:id,title,slug,owner_id,assigned_agent_id',
                'customer:id,name,email',
                'assignedAgent:id,name',
                'enquiry',
            ]),
        ]);
    }

    public function updateStatus(Request $r, Visit $visit): JsonResponse
    {
        $u = $r->user();

        $customer = (int) $visit->customer_id === (int) $u->id;

        if ($customer) {
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
                        'scheduled',
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

        $updatedVisit = DB::transaction(function () use ($visit, $d, $customer) {

            $lockedVisit = Visit::whereKey($visit->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedVisit->status, ['completed', 'cancelled'], true)) {
                abort(422, 'A completed or cancelled visit cannot be changed.');
            }

            $lockedVisit->status = $d['status'];

            if ($d['status'] === 'confirmed') {
                $lockedVisit->confirmed_at =
                    $lockedVisit->confirmed_at ?? now();
            }

            if ($d['status'] === 'completed') {
                $lockedVisit->completed_at = now();
            }

            if ($d['status'] === 'cancelled') {
                $lockedVisit->cancelled_at = now();
                $lockedVisit->cancellation_reason =
                    $d['cancellation_reason'] ?? null;
            }

            if (!$customer && array_key_exists('internal_notes', $d)) {
                $lockedVisit->internal_notes = $d['internal_notes'];
            }

            $lockedVisit->save();

            return $lockedVisit->fresh();
        });

        return response()->json([
            'message' => 'Visit updated.',
            'data' => $updatedVisit,
        ]);
    }
}
