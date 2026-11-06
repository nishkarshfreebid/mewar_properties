<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    public function ownedProperties()
    {
        return $this->hasMany(Property::class, 'owner_id');
    }

    public function assignedProperties()
    {
        return $this->hasMany(Property::class, 'assigned_agent_id');
    }

    public function customerEnquiries()
    {
        return $this->hasMany(Enquiry::class, 'customer_id');
    }

    public function assignedEnquiries()
    {
        return $this->hasMany(Enquiry::class, 'assigned_agent_id');
    }

    public function customerVisits()
    {
        return $this->hasMany(Visit::class, 'customer_id');
    }

    public function customerPayments()
    {
        return $this->hasMany(Payment::class, 'customer_id');
    }

    public function hasRole(string $name): bool
    {
        return $this->roles()->where('name', $name)->exists();
    }

    public function hasPermission(string $name): bool
    {
        return $this->roles()->whereHas(
            'permissions', fn ($query) => $query->where('permissions.name', $name)
        )->exists();
    }
}
