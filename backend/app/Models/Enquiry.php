<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Enquiry extends Model {
    use SoftDeletes;
    protected $fillable = ['property_id','customer_id','assigned_agent_id','customer_name','customer_email','customer_phone','message','status','source','last_contacted_at','closed_at','closing_notes'];
    protected function casts(): array { return ['last_contacted_at'=>'datetime','closed_at'=>'datetime']; }
    public function property(){ return $this->belongsTo(Property::class); }
    public function customer(){ return $this->belongsTo(User::class,'customer_id'); }
    public function assignedAgent(){ return $this->belongsTo(User::class,'assigned_agent_id'); }
    public function activities(){ return $this->hasMany(EnquiryActivity::class)->latest(); }
    public function visits(){ return $this->hasMany(Visit::class); }
    public function payments(){ return $this->hasMany(Payment::class); }
}
