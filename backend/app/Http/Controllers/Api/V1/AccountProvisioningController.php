<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountProvisioningController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $expectedToken = (string) config('services.account_provisioning_token');
        $providedToken = (string) $request->header('X-Setup-Token');

        if (
            $expectedToken === '' ||
            !hash_equals($expectedToken, $providedToken)
        ) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
            'role' => ['required', 'in:admin,agent,staff'],
        ]);

        $email = Str::lower(trim($data['email']));

        $user = DB::transaction(function () use ($data, $email) {
            if (User::where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['This email address is already registered.'],
                ]);
            }

            $role = Role::where('name', $data['role'])->firstOrFail();

            $user = User::create([
                'name' => trim($data['name']),
                'email' => $email,
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $user->roles()->attach($role->id);

            return $user->load('roles.permissions');
        });

        return response()->json([
            'message' => 'Account created successfully.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $data['role'],
                'is_active' => $user->is_active,
            ],
        ], 201);
    }
}
