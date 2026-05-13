<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Guardian extends Model {
    protected $fillable = ['family_id','user_id','relationship','is_primary','can_pickup','can_receive_billing','billing_share_pct'];
    protected $casts = ['is_primary'=>'boolean','can_pickup'=>'boolean','can_receive_billing'=>'boolean','billing_share_pct'=>'decimal:2'];
    public $timestamps = false;
    public function family(): BelongsTo { return $this->belongsTo(Family::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
