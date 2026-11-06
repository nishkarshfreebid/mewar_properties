<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    protected $fillable = [
        'payment_id', 'payment_attempt_id', 'provider',
        'provider_event_id', 'event_type', 'processing_status',
        'occurred_at', 'processed_at', 'processing_attempts',
        'processing_error', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function attempt()
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }
}
