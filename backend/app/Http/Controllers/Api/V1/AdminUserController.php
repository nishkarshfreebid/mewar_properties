<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    /**
     * List users with search, role filter, status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', 'string', 'exists:roles,name'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::with('roles:id,name,display_name')->latest();

        if (!empty($data['search'])) {
            $search = trim($data['search']);

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($data['role'])) {
            $query->whereHas('roles', function ($q) use ($data) {
                $q->where('roles.name', $data['role']);
            });
        }

        if (($data['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($data['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        $users = $query->paginate($data['per_page'] ?? 15);

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'data' => $users->through(
                fn(User $user) => $this->userPayload($user)
            ),
        ]);
    }

    /**
     * Create a user and assign roles.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', 'exists:roles,name'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (!$request->user()->hasPermission('user.assign_roles')) {
            throw ValidationException::withMessages([
                'roles' => ['You do not have permission to assign user roles.'],
            ]);
        }

        $result = DB::transaction(function () use ($data) {
            $roles = Role::whereIn('name', $data['roles'])->get();

            if ($roles->count() !== count($data['roles'])) {
                throw ValidationException::withMessages([
                    'roles' => ['One or more selected roles are invalid.'],
                ]);
            }

            $user = User::create([
                'name' => trim($data['name']),
                'email' => strtolower(trim($data['email'])),
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $user->roles()->sync($roles->modelKeys());

            return $user->load('roles.permissions');
        });

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $this->userPayload($result, true),
        ], 201);
    }

    /**
     * Show a single user.
     */
    public function show(User $user): JsonResponse
    {
        $user->load('roles.permissions');

        return response()->json([
            'data' => $this->userPayload($user, true),
        ]);
    }

    /**
     * Update basic user details.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);

        $user->fill(array_filter(
            $data,
            fn($value) => $value !== null
        ));

        $user->save();

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $this->userPayload(
                $user->fresh()->load('roles.permissions'),
                true
            ),
        ]);
    }

    /**
     * Activate or deactivate a user.
     */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $actor = $request->user();
        $newStatus = (bool) $data['is_active'];

        if ($actor->is($user) && !$newStatus) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        if (
            !$newStatus &&
            $user->is_active &&
            $this->isLastActiveAdmin($user)
        ) {
            throw ValidationException::withMessages([
                'is_active' => [
                    'The last active administrator cannot be deactivated.',
                ],
            ]);
        }

        DB::transaction(function () use ($user, $newStatus) {
            $user->update(['is_active' => $newStatus]);

            if (!$newStatus) {
                $user->tokens()->delete();
            }
        });

        return response()->json([
            'message' => $newStatus
                ? 'User activated successfully.'
                : 'User deactivated and sessions revoked successfully.',
            'data' => $this->userPayload(
                $user->fresh()->load('roles.permissions'),
                true
            ),
        ]);
    }

    /**
     * Assign roles to a user.
     */
    public function assignRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', 'exists:roles,name'],
        ]);

        $roles = Role::whereIn('name', $data['roles'])->get();

        if ($roles->count() !== count($data['roles'])) {
            throw ValidationException::withMessages([
                'roles' => ['One or more selected roles are invalid.'],
            ]);
        }

        $willRemainAdmin = $roles->contains('name', 'admin');

        if ($this->isLastActiveAdmin($user) && !$willRemainAdmin) {
            throw ValidationException::withMessages([
                'roles' => [
                    'The last active administrator cannot lose the admin role.',
                ],
            ]);
        }

        $user->roles()->sync($roles->modelKeys());

        return response()->json([
            'message' => 'User roles updated successfully.',
            'data' => $this->userPayload(
                $user->fresh()->load('roles.permissions'),
                true
            ),
        ]);
    }

    /**
     * List available roles.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::withCount('users')
            ->orderBy('display_name')
            ->get();

        return response()->json([
            'data' => $roles,
        ]);
    }

    /**
     * Show role details and permissions.
     */
    public function showRole(Role $role): JsonResponse
    {
        $role->load('permissions');

        $availablePermissions = Permission::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'role' => $role,
                'available_permissions' => $availablePermissions,
            ],
        ]);
    }
    /**
     * Update permissions assigned to a role.
     */
    public function updateRolePermissions(
        Request $request,
        Role $role
    ): JsonResponse {
        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => [
                'required',
                'string',
                'distinct',
                'exists:permissions,name',
            ],
        ]);

        $permissions = Permission::whereIn('name', $data['permissions'])
            ->get();

        if ($permissions->count() !== count($data['permissions'])) {
            throw ValidationException::withMessages([
                'permissions' => ['One or more permissions are invalid.'],
            ]);
        }

        if ($role->name === 'admin') {
            $required = [
                'dashboard.view',
                'user.view',
                'user.create',
                'user.update',
                'user.assign_roles',
                'user.manage_roles',
            ];

            $missing = array_diff($required, $data['permissions']);

            if (!empty($missing)) {
                throw ValidationException::withMessages([
                    'permissions' => [
                        'The administrator role must retain all essential management permissions.',
                    ],
                ]);
            }
        }

        $role->permissions()->sync($permissions->modelKeys());

        return response()->json([
            'message' => 'Role permissions updated successfully.',
            'data' => $role->fresh()->load('permissions'),
        ]);
    }

    /**
     * Check whether this user is the last active administrator.
     */
    private function isLastActiveAdmin(User $user): bool
    {
        if (!$user->is_active || !$user->hasRole('admin')) {
            return false;
        }

        return User::where('is_active', true)
            ->whereHas('roles', function ($query) {
                $query->where('roles.name', 'admin');
            })
            ->count() <= 1;
    }

    /**
     * Standard user response.
     */
    private function userPayload(
        User $user,
        bool $includePermissions = false
    ): array {
        $roles = $user->roles;

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $roles->map(fn(Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ])->values(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        if ($includePermissions) {
            $payload['permissions'] = $roles
                ->flatMap(fn(Role $role) => $role->permissions->pluck('name'))
                ->unique()
                ->values();
        }

        return $payload;
    }
}
