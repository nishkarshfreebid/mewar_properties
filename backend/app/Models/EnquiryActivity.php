<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EnquiryActivity extends Model {
    protected $fillable = ['enquiry_id','user_id','activity_type','subject','notes','old_status','new_status','follow_up_at','metadata'];
    protected function casts(): array { return ['follow_up_at'=>'datetime','metadata'=>'array']; }
    public function enquiry(){ return $this->belongsTo(Enquiry::class); }
    public function user(){ return $this->belongsTo(User::class); }
}
