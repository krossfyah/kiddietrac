<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Notification extends Model {
    protected $fillable = ['user_id','type','title','body','data','read_at'];
    protected $casts = ['data'=>'array','read_at'=>'datetime'];
    public $timestamps = ['created_at'];
    const UPDATED_AT = null;
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
