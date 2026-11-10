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
    // Admin can view all properties with the required permission.
    if ($user->hasRole('admin')) {
        return $user->hasPermission('property.view_published');
    }

    // Agents can view only properties assigned to themselves.
    if ($user->hasRole('agent')) {
        return (int) $property->assigned_agent_id === (int) $user->id
            && $user->hasPermission('property.update_assigned');
    }

    // Owners can view their own properties.
    if (
        (int) $property->owner_id === (int) $user->id
        && $user->hasPermission('property.manage_own')
    ) {
        return true;
    }

    // Preserve public visibility for other authenticated users.
    return $property->isPubliclyVisible();
}


    public function create(User $user): bool
    {
        return $user->hasPermission('property.create');
    }

    public function update(User $user, Property $property): bool
    {
        // Admin can update any property.
        if ($user->hasRole('admin')) {
            return $user->hasPermission('property.manage_own');
        }

        // Owner can update only their own properties.
        if (
            (int) $property->owner_id === (int) $user->id
            && $user->hasPermission('property.manage_own')
        ) {
            return true;
        }

        // Agent can update only properties assigned to them.
        return (int) $property->assigned_agent_id === (int) $user->id
            && $user->hasPermission('property.update_assigned');
    }

    public function delete(User $user, Property $property): bool
    {
        if ($user->hasRole('admin')) {
            return $user->hasPermission('property.manage_own');
        }

        return (int) $property->owner_id === (int) $user->id
            && $user->hasPermission('property.manage_own')
            && $property->approval_status !== 'approved'
            && $property->publication_status !== 'published';
    }

    public function approve(User $user, Property $property): bool
    {
        return $user->hasRole('admin')
            && $user->hasPermission('property.approve')
            && $property->approval_status !== 'approved';
    }

    public function publish(User $user, Property $property): bool
    {
        return $user->hasRole('admin')
            && $user->hasPermission('property.publish')
            && $property->approval_status === 'approved';
    }

    public function assignAgent(User $user, Property $property): bool
    {
        return $user->hasRole('admin')
            && $user->hasPermission('property.assign_agent');
    }
}
