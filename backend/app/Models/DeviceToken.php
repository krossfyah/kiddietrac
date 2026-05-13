<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DeviceToken extends Model {
    protected $fillable = ['user_id','token','platform','device_name','last_active_at'];
    protected $casts = ['last_active_at' => 'datetime'];
    public $timestamps = ['created_at'];
    const UPDATED_AT = null;
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
