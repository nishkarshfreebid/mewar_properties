<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Visit extends Model
{
    use SoftDeletes;
    protected $fillable = ['property_id', 'customer_id', 'enquiry_id', 'assigned_agent_id', 'scheduled_at', 'duration_minutes', 'status', 'confirmed_at', 'completed_at', 'cancelled_at', 'customer_notes', 'internal_notes', 'cancellation_reason'];
    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'confirmed_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'duration_minutes' => 'integer'];
    }
    public function property()
    {
        return $this->belongsTo(Property::class);
    }
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
    public function enquiry()
    {
        return $this->belongsTo(Enquiry::class);
    }
    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function activities()
    {
        return $this->hasMany(VisitActivity::class)
            ->with('user:id,name,email')
            ->orderBy('created_at');
    }
}
