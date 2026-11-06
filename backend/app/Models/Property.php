<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_id', 'assigned_agent_id', 'approved_by', 'title', 'slug',
        'description', 'property_type', 'listing_type', 'price', 'bedrooms',
        'bathrooms', 'area', 'area_unit', 'address_line', 'locality', 'city',
        'state', 'postal_code', 'country', 'latitude', 'longitude',
        'approval_status', 'approved_at', 'rejection_reason',
        'publication_status', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'area' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function media()
    {
        return $this->hasMany(PropertyMedia::class)->orderBy('sort_order');
    }

    public function enquiries()
    {
        return $this->hasMany(Enquiry::class);
    }

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->approval_status === 'approved'
            && $this->publication_status === 'published';
    }
}
