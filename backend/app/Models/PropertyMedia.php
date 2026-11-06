<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PropertyMedia extends Model
{
    protected $table = 'property_media';

    protected $fillable = [
        'property_id',
        'uploaded_by',
        'media_type',
        'disk',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'caption',
        'sort_order',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->file_path);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
