<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    protected $fillable = [
        'payment_id', 'attempt_number', 'payment_gateway',
        'provider_order_id', 'provider_payment_id', 'status',
        'amount', 'currency', 'failure_code', 'failure_message',
        'initiated_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function events()
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
