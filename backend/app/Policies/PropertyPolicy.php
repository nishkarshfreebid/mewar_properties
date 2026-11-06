<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Property $property): bool
    {
        return $user->hasRole('admin')
            || (int) $property->owner_id === (int) $user->id
            || (int) $property->assigned_agent_id === (int) $user->id
            || $property->isPubliclyVisible();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('owner');
    }

    public function update(User $user, Property $property): bool
    {
        return $user->hasRole('admin')
            || (int) $property->owner_id === (int) $user->id
            || (
                $property->assigned_agent_id !== null
                && (int) $property->assigned_agent_id === (int) $user->id
            );
    }

    public function delete(User $user, Property $property): bool
    {
        return $user->hasRole('admin')
            || (
                (int) $property->owner_id === (int) $user->id
                && $property->approval_status !== 'approved'
                && $property->publication_status !== 'published'
            );
    }

    public function approve(User $user, Property $property): bool
    {
        return $user->hasRole('admin')
            && $property->approval_status !== 'approved';
    }

    public function publish(User $user, Property $property): bool
    {
        return $user->hasRole('admin')
            && $property->approval_status === 'approved';
    }

    public function assignAgent(User $user, Property $property): bool
    {
        return $user->hasRole('admin');
    }
}
