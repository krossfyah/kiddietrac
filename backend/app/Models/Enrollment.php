<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Enrollment extends Model {
    protected $fillable = ['child_id','room_id','start_date','end_date','schedule','monthly_fee','cwelcc_eligible','notes'];
    protected $casts = ['start_date'=>'date','end_date'=>'date','schedule'=>'array','cwelcc_eligible'=>'boolean','monthly_fee'=>'decimal:2'];
    public $timestamps = false;
    public function child(): BelongsTo { return $this->belongsTo(Child::class); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
}
