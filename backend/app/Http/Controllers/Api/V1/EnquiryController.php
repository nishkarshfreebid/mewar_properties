<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\EnquiryActivity;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnquiryController extends Controller {
private function canView(Request $r, Enquiry $e): bool
{
    $u = $r->user();
    $p = $e->property;

    return $u->hasRole('admin')
        || (int) $e->customer_id === (int) $u->id
        || (int) $e->assigned_agent_id === (int) $u->id
        || ($p !== null && (
            (int) $p->owner_id === (int) $u->id
            || (int) $p->assigned_agent_id === (int) $u->id
        ));
}

private function canManage(Request $r, Enquiry $e): bool
{
    $u = $r->user();
    $p = $e->property;

    return $u->hasRole('admin')
        || ($p !== null
            && $u->hasRole('owner')
            && (int) $p->owner_id === (int) $u->id)
        || ($p !== null
            && $u->hasRole('agent')
            && (
                (int) $e->assigned_agent_id === (int) $u->id
                || (int) $p->assigned_agent_id === (int) $u->id
            ));
}

public function index(Request $r): JsonResponse
{
    $u = $r->user();

    $q = Enquiry::with([
        'property:id,title,slug,owner_id,assigned_agent_id',
        'customer:id,name,email',
        'assignedAgent:id,name',
    ]);

    // Admin can view all enquiries.
    if (!$u->hasRole('admin')) {

        $hasAccessRole = $u->hasRole('customer')
            || $u->hasRole('owner')
            || $u->hasRole('agent');

        abort_unless($hasAccessRole, 403);

        $q->where(function ($scope) use ($u) {

            // Customer: enquiries submitted by the customer.
            if ($u->hasRole('customer')) {
                $scope->orWhere('customer_id', $u->id);
            }

            // Owner: enquiries received for their properties.
            if ($u->hasRole('owner')) {
                $scope->orWhereHas('property', function ($property) use ($u) {
                    $property->where('owner_id', $u->id);
                });
            }

            // Agent: enquiries assigned directly or through a property.
            if ($u->hasRole('agent')) {
                $scope->orWhere('assigned_agent_id', $u->id)
                    ->orWhereHas('property', function ($property) use ($u) {
                        $property->where('assigned_agent_id', $u->id);
                    });
            }
        });
    }

    // Optional status filter.
    if ($r->filled('status')) {
        $r->validate([
            'status' => [
                'string',
                Rule::in([
                    'new',
                    'contacted',
                    'qualified',
                    'closed',
                    'lost',
                ]),
            ],
        ]);

        $q->where('status', $r->input('status'));
    }

    return response()->json([
        'data' => $q->latest()->paginate(15),
    ]);
}

    public function store(Request $r): JsonResponse {
        $u=$r->user(); abort_unless($u->hasRole('customer'),403,'Only customers can submit enquiries.');
        $d=$r->validate(['property_id'=>['required','integer','exists:properties,id'],'message'=>['nullable','string','max:5000'],'customer_phone'=>['nullable','string','max:30']]);
        $p=Property::whereKey($d['property_id'])->where('approval_status','approved')->where('publication_status','published')->firstOrFail();
        $e=DB::transaction(function() use($u,$p,$d) {
            $e=Enquiry::create(['property_id'=>$p->id,'customer_id'=>$u->id,'assigned_agent_id'=>$p->assigned_agent_id,'customer_name'=>$u->name,'customer_email'=>$u->email,'customer_phone'=>$d['customer_phone']??null,'message'=>$d['message']??null,'status'=>'new','source'=>'website']);
            EnquiryActivity::create(['enquiry_id'=>$e->id,'user_id'=>$u->id,'activity_type'=>'created','subject'=>'Enquiry submitted','new_status'=>'new']);
            return $e;
        });
        return response()->json(['message'=>'Enquiry submitted successfully.','data'=>$e->load(['property','customer:id,name,email'])],201);
    }
public function show(Request $r, Enquiry $enquiry): JsonResponse
{
    abort_unless($this->canView($r, $enquiry), 403);

    $user = $r->user();

    $rels = [
        'property:id,title,slug,owner_id,assigned_agent_id',
        'customer:id,name,email',
        'assignedAgent:id,name',
        'visits',
    ];

    $isCustomerOnly = $user->hasRole('customer')
        && !$user->hasRole('owner')
        && !$user->hasRole('agent')
        && !$user->hasRole('admin');

    if (!$isCustomerOnly) {
        $rels[] = 'activities.user:id,name';
    }

    return response()->json([
        'data' => $enquiry->load($rels),
    ]);
}
public function updateStatus(Request $r, Enquiry $enquiry): JsonResponse
{
    abort_unless($this->canManage($r, $enquiry), 403);

    $d = $r->validate([
        'status' => [
            'required',
            Rule::in(['new', 'contacted', 'qualified', 'closed', 'lost']),
        ],
        'notes' => ['nullable', 'string', 'max:5000'],
        'follow_up_at' => ['nullable', 'date'],
        'closing_notes' => ['nullable', 'string', 'max:5000'],
    ]);

    $u = $r->user();

    DB::transaction(function () use ($enquiry, $d, $u) {

        // Lock the enquiry row during the update.
        $lockedEnquiry = Enquiry::whereKey($enquiry->id)
            ->lockForUpdate()
            ->firstOrFail();

        $oldStatus = $lockedEnquiry->status;

        $lockedEnquiry->status = $d['status'];

        if (in_array($d['status'], ['closed', 'lost'], true)) {
            $lockedEnquiry->closed_at = now();
            $lockedEnquiry->closing_notes = $d['closing_notes'] ?? null;
        } else {
            $lockedEnquiry->closed_at = null;
            $lockedEnquiry->closing_notes = null;
        }

        if ($d['status'] === 'contacted') {
            $lockedEnquiry->last_contacted_at = now();
        }

        $lockedEnquiry->save();

        EnquiryActivity::create([
            'enquiry_id' => $lockedEnquiry->id,
            'user_id' => $u->id,
            'activity_type' => 'status_changed',
            'subject' => 'Enquiry status updated',
            'notes' => $d['notes'] ?? null,
            'old_status' => $oldStatus,
            'new_status' => $d['status'],
            'follow_up_at' => $d['follow_up_at'] ?? null,
        ]);
    });

    return response()->json([
        'message' => 'Enquiry status updated.',
        'data' => $enquiry->fresh(),
    ]);
}
    public function addActivity(Request $r, Enquiry $enquiry): JsonResponse {
        abort_unless($this->canManage($r,$enquiry),403);
        $d=$r->validate(['activity_type'=>['required','string','max:50'],'subject'=>['nullable','string','max:200'],'notes'=>['nullable','string','max:5000'],'follow_up_at'=>['nullable','date']]);
        return response()->json(['data'=>$enquiry->activities()->create([...$d,'user_id'=>$r->user()->id])],201);
    }
}
