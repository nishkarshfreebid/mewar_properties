<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitActivity extends Model
{
    protected $fillable = [
        'visit_id',
        'user_id',
        'activity_type',
        'subject',
        'notes',
        'old_status',
        'new_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function visit()
    {
        return $this->belongsTo(Visit::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
