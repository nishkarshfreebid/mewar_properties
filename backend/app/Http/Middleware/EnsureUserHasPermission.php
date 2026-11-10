<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$permissions
    ): Response {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        // Supports: permission:a
        // Supports: permission:a|b|c
        $requiredPermissions = collect($permissions)
            ->flatMap(fn (string $permission) => explode('|', $permission))
            ->map(fn (string $permission) => trim($permission))
            ->filter()
            ->unique()
            ->values()
            ->all();

        abort_if(
            empty($requiredPermissions),
            500,
            'No permission was configured for this route.'
        );

        // At least one permission must be present.
        abort_unless(
            $user->hasAnyPermission($requiredPermissions),
            403,
            'You do not have permission to perform this action.'
        );

        return $next($request);
    }
}
