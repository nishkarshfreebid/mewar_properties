<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    // Deliberately exclude status, amount_paid, provider IDs and recorded_by.
    // Trusted server-side code uses forceFill()/direct assignment for state changes.
    protected $fillable = [
        'customer_id', 'property_id', 'enquiry_id', 'payment_reference',
        'purpose', 'payment_method', 'payment_gateway', 'currency',
        'amount', 'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function property() { return $this->belongsTo(Property::class); }
    public function enquiry() { return $this->belongsTo(Enquiry::class); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
    public function attempts() { return $this->hasMany(PaymentAttempt::class); }
    public function events() { return $this->hasMany(PaymentEvent::class); }
}
