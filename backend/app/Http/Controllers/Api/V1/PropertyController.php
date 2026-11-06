<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PropertyController extends Controller
{
    use AuthorizesRequests;

    public function index(): JsonResponse
    {
        $properties = Property::query()
            ->where('approval_status', 'approved')
            ->where('publication_status', 'published')
            ->with(['media', 'owner:id,name'])
            ->latest()
            ->paginate(15);

        return response()->json(['data' => $properties]);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Property::query()->with(['media', 'owner:id,name']);

        if (!$user->hasRole('admin')) {
            $query->where(function ($builder) use ($user) {
                $builder->where('owner_id', $user->id)
                    ->orWhere('assigned_agent_id', $user->id);
            });
        }

        return response()->json(['data' => $query->latest()->paginate(15)]);
    }

    public function show(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        return response()->json([
            'data' => $property->load(['media', 'owner:id,name', 'assignedAgent:id,name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Property::class);

        $user = $request->user();
        $isAdmin = $user->hasRole('admin');

        $rules = $this->propertyRules();

        if ($isAdmin) {
            $rules['owner_id'] = ['required', 'integer', 'exists:users,id'];
        }

        $data = $request->validate($rules);

        $ownerId = $isAdmin ? (int) $data['owner_id'] : $user->id;
        unset($data['owner_id']);

        $property = DB::transaction(function () use ($data, $ownerId) {
            return Property::create(array_merge($data, [
                'owner_id' => $ownerId,
                'slug' => $this->uniqueSlug($data['title']),
                'approval_status' => 'pending',
                'publication_status' => 'draft',
                'approved_by' => null,
                'approved_at' => null,
                'published_at' => null,
                'assigned_agent_id' => null,
                'rejection_reason' => null,
            ]));
        });

        return response()->json([
            'message' => 'Property created and submitted for approval.',
            'data' => $property,
        ], 201);
    }

    public function update(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $rules = $this->propertyRules(false);
        $data = $request->validate($rules);

        // Approval, publication, ownership and assignment are never changed here.
        unset(
            $data['owner_id'],
            $data['assigned_agent_id'],
            $data['approved_by'],
            $data['approval_status'],
            $data['approved_at'],
            $data['publication_status'],
            $data['published_at'],
            $data['rejection_reason'],
            $data['slug']
        );

        if (isset($data['title']) && $data['title'] !== $property->title) {
            $data['slug'] = $this->uniqueSlug($data['title']);
            $data['approval_status'] = 'pending';
            $data['approved_by'] = null;
            $data['approved_at'] = null;
            $data['publication_status'] = 'draft';
            $data['published_at'] = null;
        }

        $property->fill($data)->save();

        return response()->json([
            'message' => 'Property updated.',
            'data' => $property->fresh(),
        ]);
    }

    public function destroy(Property $property): JsonResponse
    {
        $this->authorize('delete', $property);

        $property->delete();

        return response()->json(['message' => 'Property deleted.']);
    }

    public function approve(Property $property): JsonResponse
    {
        $this->authorize('approve', $property);

        $property->forceFill([
            'approval_status' => 'approved',
            'approved_by' => request()->user()->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();

        return response()->json([
            'message' => 'Property approved. It is not public until published.',
            'data' => $property->fresh(),
        ]);
    }

    public function reject(Request $request, Property $property): JsonResponse
    {
        $this->authorize('approve', $property);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $property->forceFill([
            'approval_status' => 'rejected',
            'approved_by' => null,
            'approved_at' => null,
            'publication_status' => 'draft',
            'published_at' => null,
            'rejection_reason' => $data['rejection_reason'],
        ])->save();

        return response()->json([
            'message' => 'Property rejected.',
            'data' => $property->fresh(),
        ]);
    }

    public function publish(Property $property): JsonResponse
    {
        $this->authorize('publish', $property);

        $property->forceFill([
            'publication_status' => 'published',
            'published_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Property published.',
            'data' => $property->fresh(),
        ]);
    }

    public function unpublish(Property $property): JsonResponse
    {
        $this->authorize('publish', $property);

        $property->forceFill([
            'publication_status' => 'draft',
            'published_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Property unpublished.',
            'data' => $property->fresh(),
        ]);
    }

    public function assignAgent(Request $request, Property $property): JsonResponse
    {
        $this->authorize('assignAgent', $property);

        $data = $request->validate([
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (!empty($data['agent_id'])) {
            $agent = User::findOrFail($data['agent_id']);

            if (!$agent->hasRole('agent')) {
                return response()->json([
                    'message' => 'The selected user does not have the agent role.',
                ], 422);
            }
        }

        $property->assigned_agent_id = $data['agent_id'] ?? null;
        $property->save();

        return response()->json([
            'message' => 'Property assignment updated.',
            'data' => $property->fresh(),
        ]);
    }

    private function propertyRules(bool $creating = true): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'property_type' => [$creating ? 'required' : 'sometimes', 'string', 'max:50'],
            'listing_type' => [$creating ? 'required' : 'sometimes', 'string', 'max:30'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'bedrooms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'bathrooms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'area' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'area_unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'locality' => ['sometimes', 'nullable', 'string', 'max:150'],
            'city' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'state' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'string', 'max:100'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    private function uniqueSlug(string $title): string
    {
        do {
            $slug = Str::slug($title).'-'.Str::lower(Str::random(6));
        } while (Property::where('slug', $slug)->exists());

        return $slug;
    }
}
