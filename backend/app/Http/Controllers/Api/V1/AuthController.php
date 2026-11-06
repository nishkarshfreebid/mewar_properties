<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $role = Role::where('name', 'customer')->first();

            if (!$role) {
                throw new \RuntimeException(
                    'Customer role is missing. Run the role/permission seeder.'
                );
            }

            $user = User::create([
                'name' => trim($data['name']),
                'email' => Str::lower(trim($data['email'])),
                'password' => $data['password'],
            ]);

            $user->roles()->attach($role->id);

            return [
                'user' => $user->load('roles.permissions'),
                'token' => $user->createToken('api')->plainTextToken,
            ];
        });

        return response()->json([
            'message' => 'Registration successful.',
            'data' => [
                'user' => $this->payload($result['user']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $key = 'login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message' => 'Too many login attempts.',
                'retry_after_seconds' => RateLimiter::availableIn($key),
            ], 429);
        }

        $user = User::where('email', Str::lower($data['email']))->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'email' => ['The supplied credentials are invalid.'],
            ]);
        }

        RateLimiter::clear($key);
        $user->load('roles.permissions');

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'user' => $this->payload($user),
                'token' => $user->createToken($data['device_name'] ?? 'api')
                    ->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['user' => $this->payload(
                $request->user()->load('roles.permissions')
            )],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function payload(User $user): array
    {
        $roles = $user->roles;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $roles->pluck('name')->values(),
            'permissions' => $roles->flatMap(
                fn ($role) => $role->permissions->pluck('name')
            )->unique()->values(),
        ];
    }
}
