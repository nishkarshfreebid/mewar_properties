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



    public function adminIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user && (
                ($user->hasRole('admin') && $user->hasPermission('property.view_published'))
                || $user->hasAnyPermission([
                    'property.view_published',
                    'property.update_assigned',
                ])
            ),
            403,
            'You do not have permission to view properties.'
        );

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'property_type' => ['nullable', 'string', 'max:50'],
            'listing_type' => ['nullable', 'string', 'max:30'],
            'approval_status' => ['nullable', 'string', Rule::in(['pending', 'approved', 'rejected'])],
            'publication_status' => ['nullable', 'string', Rule::in(['draft', 'published'])],
            'city' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', Rule::in(['created_at', 'updated_at', 'price', 'title'])],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $query = Property::query()
            ->with([
                'media',
                'owner:id,name,email',
                'assignedAgent:id,name,email',
                'approver:id,name,email',
            ])
            ->withCount(['enquiries', 'visits']);

        // Admin can see all properties.

        // Admin can see all properties.
// Agents can see only properties assigned to themselves.
// Other permitted users retain the existing published-property visibility.
        if ($user->hasRole('admin')) {
            // No additional query restriction for administrators.
        } elseif ($user->hasRole('agent')) {
            $query->where('assigned_agent_id', $user->id);
        } else {
            $query->where(function ($builder) use ($user) {
                $builder->where(function ($published) {
                    $published->where('approval_status', 'approved')
                        ->where('publication_status', 'published');
                });

                if ($user->hasPermission('property.update_assigned')) {
                    $builder->orWhere('assigned_agent_id', $user->id);
                }
            });
        }


        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('locality', 'like', "%{$search}%")
                    ->orWhere('postal_code', 'like', "%{$search}%");
            });
        }

        foreach ([
            'property_type',
            'listing_type',
            'approval_status',
            'publication_status',
            'city',
            'owner_id',
            'assigned_agent_id',
        ] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $perPage = $filters['per_page'] ?? 15;

        $properties = $query
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Properties retrieved successfully.',
            'data' => $properties->items(),
            'meta' => [
                'current_page' => $properties->currentPage(),
                'last_page' => $properties->lastPage(),
                'per_page' => $properties->perPage(),
                'total' => $properties->total(),
                'from' => $properties->firstItem(),
                'to' => $properties->lastItem(),
            ],
        ]);
    }


    public function ownerOptions(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user && $user->hasRole('admin')
            && $user->hasPermission('property.create'),
            403,
            'Administrator access required.'
        );

        $owners = User::query()
            ->whereHas('roles', function ($query) {
                $query->where('roles.name', 'owner');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json([
            'success' => true,
            'data' => $owners,
        ]);
    }


    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Property::query()
            ->with(['media', 'owner:id,name']);

        if ($user->hasRole('admin')) {
            // Admin can see all properties.
        } elseif ($user->hasRole('agent')) {
            // Agent can see only assigned properties.
            $query->where('assigned_agent_id', $user->id);
        } else {
            // Preserve existing owner visibility.
            $query->where(function ($builder) use ($user) {
                $builder->where('owner_id', $user->id)
                    ->orWhere('assigned_agent_id', $user->id);
            });
        }

        return response()->json([
            'data' => $query->latest()->paginate(15),
        ]);
    }



    public function show(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        $isAdmin = request()->user()?->hasRole('admin') ?? false;

        $relations = [
            'media',
            'owner:id,name' . ($isAdmin ? ',email' : ''),
            'assignedAgent:id,name' . ($isAdmin ? ',email' : ''),
            'approver:id,name',
        ];

        return response()->json([
            'data' => $property->load($relations),
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
            $slug = Str::slug($title) . '-' . Str::lower(Str::random(6));
        } while (Property::where('slug', $slug)->exists());

        return $slug;
    }
}
